<?php

/* Copyright 2022-2025 University of Bonn
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (!isset($report_http_errors)) {
  $report_http_errors = false;
}

if (count(get_included_files()) == 1) {
  die("Direct access not permitted.");
}

function fatal_error($msg, $prefix='') {
  global $report_http_errors;
  if (ob_get_contents() !== false) {
    // Output buffering is active, flush output.
    // Flush all output:
    ob_end_flush(); // Strange behaviour, will not work
    flush();        // Unless both are called !
  }
  if ($report_http_errors === true) {
    http_response_code(500);
  }
  if ($prefix != '') {
    die("[".$prefix."] ".$msg."\r\n");
  } else {
    die($msg."\r\n");
  }
}

function printout(&$out, $msg) {
  $out .= $msg."\r\n";
  echo $msg."\r\n";
}

function validate_hmac($body, $secret, $x_hub_signature) {
  $equal_pos = strpos($x_hub_signature, '=');
  if ($equal_pos === false) {
    // Bad, we expect algo=HMAC
    fatal_error("Unexpected format of HTTP_X_HUB_SIGNATURE, should contain = character!", "HMAC");
  }
  $algo = substr($x_hub_signature, 0, $equal_pos);
  if (!in_array($algo, hash_hmac_algos())) {
    fatal_error("Unsupported HMAC algorithm ".$algo."!", "HMAC");
  }
  $received_hmac = substr($x_hub_signature, $equal_pos+1);
  $hook_hmac = hash_hmac($algo, $body, $secret);
  $hook_valid = hash_equals($hook_hmac, $received_hmac);
  if (!$hook_valid) {
    // Invalid webhook signature, ignore the request.
    fatal_error("Invalid webhook HMAC signature, should be ".$received_hmac." but found ".$hook_hmac."!", "HMAC");
  }
}

function extract_from_json($json, $json_path, $desc='JSON', $val_type='string') {
  $json_deep = $json;
  $current_path = array();
  $path_parts = explode('->', $json_path);
  foreach ($path_parts as $path_part) {
    if (!is_object($json_deep)) {
      fatal_error("Does not contain an object at ".implode('->',$current_path)."!", $desc);
    }
    if (substr($path_part, -1) == ']') {
      // This is an array index.
      if (preg_match('/^([^\(]+)\[([0-9]+)\]$/', $path_part, $matches) !== 1) {
        fatal_error("Invalid format of array subscript in".$path_part." from path ".implode('->',$current_path)."!", $desc);
      }
      $real_path_part = $matches[1];
      $array_index = $matches[2];
      if (!property_exists($json_deep, $real_path_part)) {
        fatal_error("Does not contain ".implode('->',$current_path+[$real_path_part])."!", $desc);
      }
      $json_deep = $json_deep->{$real_path_part};
      if (!is_array($json_deep)) {
        fatal_error("Does not contain an array at ".implode('->',$current_path+[$real_path_part])."!", $desc);
      }
      if ($array_index > (count($json_deep)-1)) {
        fatal_error("Array index ".$array_index." too large for array at ".implode('->',$current_path+[$real_path_part])." with count ".count($json_deep)."!", $desc);
      }
      $json_deep = $json_deep[$array_index];
      array_push($current_path, $path_part);
    } else {
      if (!property_exists($json_deep, $path_part)) {
        fatal_error("Does not contain ".implode('->',$current_path+[$path_part])."!", $desc);
      }
      $json_deep = $json_deep->{$path_part};
      array_push($current_path, $path_part);
    }
    //echo $path_part." was ok\r\n";
  }
  switch ($val_type) {
    case "string":
      if (!is_string($json_deep)) {
        fatal_error(implode('->',$current_path)." is not a string!", $desc);
      }
      break;
    case "object":
      if (!is_object($json_deep)) {
        fatal_error(implode('->',$current_path)." is not an object!", $desc);
      }
      break;
    case "array":
      if (!is_array($json_deep)) {
        fatal_error(implode('->',$current_path)." is not an array!", $desc);
      }
      break;
    case "bool":
      if (!is_bool($json_deep)) {
        fatal_error(implode('->',$current_path)." is not a bool!", $desc);
      }
      break;
    default:
      fatal_error("Type validation for ".$val_type." not implemented!", $desc);
  }
  return $json_deep;
}

?>
