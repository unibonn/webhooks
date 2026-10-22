<?php

/* Copyright 2022-2025 University of Bonn
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

date_default_timezone_set('Europe/Berlin');

// Not liked by BitBucket.
$report_http_errors = false;
include("./include/functions.php");

$config = json_decode(file_get_contents('/etc/webhook/bitbucket.json'));
if ($config === null) {
  die("Invalid configuration!");
}

// Can be used for debugging: Stores headers and hook in files, which can be re-read.
$test_mode = false;

$server_env = $_SERVER;
$webhook_body = file_get_contents('php://input');

if ($test_mode) {
  file_put_contents('/tmp/last_server_vars.dat', serialize($server_env));
  $server_env = unserialize(file_get_contents('server_vars.dat'));

  file_put_contents('/tmp/last_hook.dat', serialize($webhook_body));
  $webhook_body = unserialize(file_get_contents('webhook_body.dat'));
}

if ($server_env['REQUEST_METHOD'] !== 'POST') {
  die("Wrong request method, only POST accepted!");
}
$x_hub_signature = $server_env['HTTP_X_HUB_SIGNATURE'] ?? '';
if ($x_hub_signature == '') {
  die("No HTTP_X_HUB_SIGNATURE received!");
}

// Parse webhook to extract repository name and project key.
$webhook = json_decode($webhook_body);
if ($webhook === null) {
  die("Invalid JSON!");
}

// Extract repo information from webhook.
$repository_name = extract_from_json($webhook, "repository->name", "Webhook", "string");
$project_key = extract_from_json($webhook, "repository->project->key", "Webhook", "string");

// Extract corresponding secret key from configuration.
$project_config = extract_from_json($config, $project_key, "Configuration::GetProject", "object");
$repository_config = extract_from_json($project_config, $repository_name, "Configuration::GetRepository", "object");
$hook_secret = extract_from_json($repository_config, "secret", "Configuration::GetSecret", "string");

// Check HMAC.
validate_bitbucket_hmac($webhook_body, $hook_secret, $x_hub_signature);

// Extract further information of interest from the webhook.
$event_key = extract_from_json($webhook, "eventKey", "Webhook", "string");
if ($event_key !== "repo:refs_changed") {
  fatal_error("Event key ".$event_key." not handled, ignoring hook!", "hook_validation");
}
$changes_type = extract_from_json($webhook, "changes[0]->type", "Webhook", "string");
if ($changes_type != "UPDATE") {
  fatal_error("Changes type ".$changes_type." not handled, ignoring hook!", "hook_validation");
}

$affected_ref_id = extract_from_json($webhook, "changes[0]->refId", "Webhook", "string");
if (!property_exists($repository_config, $affected_ref_id)) {
  fatal_error("No configuration for refId ".$affected_ref_id." found in configuration for ".$project_key."/".$repository_name.", ignoring hook!", "config_check");
}

// Check configuration for command execution.
$ref_config = extract_from_json($repository_config, $affected_ref_id, "Configuration::GetRefConfig", "object");

$config_levels = [ 'project', 'repository', 'ref' ];

$alert_mail = "";
$notice_mail = "";
if (property_exists($config, "alert_mail")) {
  $alert_mail = extract_from_json($config, "alert_mail", "Configuration::GetAlertMail::Global", "string");
}
if (property_exists($config, "notice_mail")) {
  $notice_mail = extract_from_json($config, "notice_mail", "Configuration::GetNoticeMail::Global", "string");
}
$continue_on_error = extract_from_json($config, "continue_on_error", "Configuration::GetContinueOnError::Global", "bool");
$background = extract_from_json($config, "background", "Configuration::GetBackground::Global", "bool");
$from_mail = extract_from_json($config, "from_mail", "Configuration::GetFromMail::Global", "string");
$reply_to_mail = extract_from_json($config, "reply_to_mail", "Configuration::GetReplyToMail::Global", "string");
$description = "";
foreach ($config_levels as $cfg_level) {
  $level_var = $cfg_level."_config";
  if (property_exists($$level_var, "alert_mail")) {
    $alert_mail = extract_from_json($$level_var, "alert_mail", "Configuration::GetAlertMail::".$cfg_level, "string");
  }
  if (property_exists($$level_var, "notice_mail")) {
    $notice_mail = extract_from_json($$level_var, "notice_mail", "Configuration::GetNoticeMail::".$cfg_level, "string");
  }
  if (property_exists($$level_var, "continue_on_error")) {
    $continue_on_error = extract_from_json($$level_var, "continue_on_error", "Configuration::GetContinueOnError::".$cfg_level, "bool");
  }
  if (property_exists($$level_var, "background")) {
    $background = extract_from_json($$level_var, "background", "Configuration::GetBackground::".$cfg_level, "bool");
  }
  if (property_exists($$level_var, "from_mail")) {
    $from_mail = extract_from_json($$level_var, "from_mail", "Configuration::GetFromMail::".$cfg_level, "string");
  }
  if (property_exists($$level_var, "reply_to_mail")) {
    $reply_to_mail = extract_from_json($$level_var, "reply_to_mail", "Configuration::GetReplyToMail::".$cfg_level, "string");
  }
  if (property_exists($$level_var, "description")) {
    $description = extract_from_json($$level_var, "description", "Configuration::GetDescription::".$cfg_level, "string");
  }
}

$ref_cmds = extract_from_json($ref_config, "commands", "Configuration::GetRefConfig", "array");

// Continue even if connection is closed:
ignore_user_abort();

/* Go to background before command execution. Inspired by:
   https://www.php.net/manual/de/features.connection-handling.php#71172
   Needed due to limited BitBucket response waiting time:
   - plugin.webhooks.socket.timeout, 20 s
   - plugin.com.atlassian.stash.plugin.hook.connectionTimeout, 10 s
*/
if ($background) {
  // If buffering active, ensure output buffer is clean and zeroed, start fresh buffer.
  if (ob_get_contents()) ob_end_clean();
  header("Connection: close");
  ob_start();
}

$fail_cnt = 0;
$out = "";
$cmd_cnt = count($ref_cmds);
printout($out, "Received valid hook for ".$project_key."/".$repository_name.":".$affected_ref_id.".");
if (!empty($description)) {
  printout($out, "Description: ".$description);
}
printout($out, "Executing ".$cmd_cnt." configured commands...");
printout($out, "");

if ($background) {
  // These are our last words for the listener, then we background.
  printout($out, "Backgrounding has been requested due to potentially extensive execution time.");
  printout($out, "The following ".$cmd_cnt." commands will be executed in the background:");
  printout($out, "------");
  foreach ($ref_cmds as $cmd_num => $cmd) {
    printout($out, "  ".($cmd_num+1)."/".$cmd_cnt.":  $ ".$cmd);
  }
  printout($out, "------");
  if (!empty($notice_mail)) {
    printout($out, "In case all commands exit successfully, will notify: ".$notice_mail);
  } else {
    printout($out, "In case all commands exit successfully, nobody will be noticed!");
  }
  if (!empty($alert_mail)) {
    printout($out, "In case any command errors out, will notify: ".$alert_mail);
  } else {
    printout($out, "In case any command errors out, nobody will be noticed!");
  }
  if ($continue_on_error) {
    printout($out, "Note that execution will stop on first error.");
  } else {
    printout($out, "Note execution will continue even if errors are encountered.");
  }
  printout($out, "Terminating connection and backgrounding now...");
  printout($out, "------");
  $size = ob_get_length();
  header("Content-Length: $size");
  ob_end_flush(); // Strange behaviour, will not work
  flush();        // Unless both are called !
  // Start a new output buffer, which will be discarded at the end - nobody is listening to any echo anymore:
  ob_start();
  printout($out, "Now starting the real work...\r\n");
}

foreach ($ref_cmds as $cmd_num => $cmd) {
  $output = null;
  $exitcode = null;
  printout($out, "Executing command ".($cmd_num+1)."/".$cmd_cnt.":");
  printout($out, "  $ ".$cmd);
  exec($cmd." 2>&1", $output, $exitcode);
  printout($out, "  Exit code: ".$exitcode);
  printout($out, "  Output:");
  foreach ($output as $oline) {
    printout($out, "    ".$oline);
  }
  printout($out, "");
  if ($exitcode != 0) {
    $fail_cnt++;
    if ($continue_on_error === false) {
      printout($out, "continue_on_error is set to false, stopping execution here!");
      printout($out, "");
      break;
    }
  }
}

$mail_headers = "From: [".gethostname()."] ".$from_mail."\r\n".
                "Reply-To: ".$reply_to_mail."\r\n".
                "X-Mailer: PHP/".phpversion();

if ($fail_cnt == 0) {
  printout($out, "All commands have returned a healthy exit code :-).");
  if (!empty($notice_mail)) {
    printout($out, "Sending out notice mail(s) to ".$notice_mail."...");
    $mail_to = $notice_mail;
    $mail_subject = "[webhook][".$project_key."/".$repository_name.":".$affected_ref_id."] Successful BitBucket webhook execution (".$cmd_cnt." succeeded)";
    $mail_body = "Dear expert(s),\r\n\r\n";
    $mail_body .= $cmd_cnt." commands succeeded during execution of the webhook commands for ".$project_key."/".$repository_name.":".$affected_ref_id.".\r\n";
    $mail_body .= "You find the detailed output below.\r\n\r\n";
    $mail_body .= "--------------------------------------------------------------------------------\r\n";
    $mail_body .= $out."\r\n";
    $mail_body .= "--------------------------------------------------------------------------------\r\n\r\n";
    $mail_body .= "All the best,\r\n";
    $mail_body .= "your friendly webhook execution script.\r\n";
    if (mail($mail_to, $mail_subject, $mail_body, $mail_headers) !== true) {
      fatal_error("Trying to send notice mail failed!", "notice_mail");
    }
  } else {
    printout($out, "Not sending out notice mail(s), notice_mail unset or set to empty string.");
  }
} else {
  printout($out, "Warning: Some commands have errored out! :-(");
  if (!empty($alert_mail)) {
    printout($out, "Sending out alert mail(s) to ".$alert_mail."...");
    $mail_to = $alert_mail;
    $mail_subject = "[webhook][".$project_key."/".$repository_name.":".$affected_ref_id."] Failures during BitBucket webhook execution (".$fail_cnt."/".$cmd_cnt." failed)!";
    $mail_body = "Dear expert(s),\r\n\r\n";
    $mail_body .= $fail_cnt."/".$cmd_cnt." commands failed during execution of the webhook commands for ".$project_key."/".$repository_name.":".$affected_ref_id."!\r\n";
    $mail_body .= "Please check the output below carefully and take appropriate action(s).\r\n\r\n";
    $mail_body .= "--------------------------------------------------------------------------------\r\n";
    $mail_body .= $out."\r\n";
    $mail_body .= "--------------------------------------------------------------------------------\r\n\r\n";
    $mail_body .= "All the best,\r\n";
    $mail_body .= "your friendly webhook execution script.\r\n";
    if (mail($mail_to, $mail_subject, $mail_body, $mail_headers) !== true) {
      fatal_error("Trying to send alert mail failed!", "alert_mail");
    }
  } else {
    printout($out, "Not sending out alert mail(s), alert_mail unset or set to empty string.");
  }
}

if ($background) {
  // Discard any remaining output, nobody listens anyways (may cause issues otherwise!).
  ob_end_clean();
} else {
  // Flush all output:
  ob_end_flush(); // Strange behaviour, will not work
  flush();        // Unless both are called !
}

?>
