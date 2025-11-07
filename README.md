# webhooks

PHP webhook handling code for webhooks from various tools.

## Documentation

### `gitlab.php`

Deals with webhooks from GitLab. Webhooks are validated via the defined secret (GitLab token) in the webhook configuration, otherwise, default configuration for a `push` hook is expected (i.e. no customized JSON payload).
An example JSON configuration is provided in `sample_config/gitlab.json`.
It has a hierarchical structure with global settings and the project keys as top-level keys.
Configuration is described [in Git push hooks](#git-push-hooks).

#### Limitations

The code currently handles object kind `push` and event name `push` only, i.e. i.e. pushes.
It tests that the `ref` matches the configured ID. Otherwise, the code exits with an error.

### `bitbucket.php`

Deals with webhooks from BitBucket. Webhooks are validated via the defined secret in the webhook configuration.
An example JSON configuration is provided in `sample_config/bitbucket.json`.
It has a hierarchical structure with global settings and the project keys as top-level keys.
Configuration is described [in Git push hooks](#git-push-hooks).

#### Limitations

The code currently handles `repo:refs_changed` event keys only (i.e. pushes) and only looks at the first change.
It tests that this was an `UPDATE` and that the `refId` matches the configured ID. Otherwise, the code exits with an error.

### Git push hooks

For each project, a repository and ref-id for which the webhook should be executed are given, i.e. in a pseudo-example:
```json
{
  "global_setting": "value",
  "PROJKEY": {
    "project_specific_setting": "value",
    "reponame": {
      "repo_specific_setting": "value",
      "refs/heads/master": {
        "ref_id_specific_setting": "value"
      }
    }
}
```
Not all configuration knobs can be set on all levels, and some are optional.  
If a knob is set on multiple levels, the deepest setting is used. This allows to define defaults on global level and override them on deeper levels.

The following configuration knobs exist:

- `alert_mail` (String, optional)  
   Can be set to a list of mail addresses, mailed only in case of error.  
   If set to an empty string, no alerts are sent.  
   Possible levels in configuration file: Global, Project, Repository, Ref-Id.  
   Example value: `Admin A <admin.a@example.com>, Admin B <admin.b@example.com>`

- `notice_mail` (String, optional)  
   Can be set to a list of mail addresses, mailed only in case of success.  
   If set to an empty string, no notices are sent.  
   Possible levels in configuration file: Global, Project, Repository, Ref-Id.  
   Example value: `Expert A <expert.a@example.com>, Admin A <admin.a@example.com>`

- `description` (String, optional, on project level or below)  
   The provided description will be echoed during webhook execution.  
   This provides a comment / naming functionality.  
   If set to an empty string (implicit default), the description is not added to the output.  
   Possible levels in configuration file: Project, Repository, Ref-Id.  
   Example value: `Syncing code, configuration stored in /etc/synccode.conf`

- `continue_on_error` (Boolean, required on Global level)  
   If set to `true`, abort when the first command fails. Otherwise, continues execution.  

- `background` (Boolean, required on Global level)  
   If set to `true`, informative output is returned and the connection to Bitbucket is closed gracefully.  
   Execution continues in the background, and mail notices / alerts will be sent as configured.  
   Note that if set to `false`, while the full output will be returned to Bitbucket,
   Bitbucket may mark webhooks as "failed" after given time limits have passed, configurable via:
   - `plugin.webhooks.socket.timeout` (default 20 s)
   - `plugin.com.atlassian.stash.plugin.hook.connectionTimeout` (default 10 s)
   Possible levels in configuration file: Global, Project, Repository, Ref-Id.

- `from_mail` (String, required on Global level)  
   Mail address to use in the `From` header.  
   Can be set to a name and mail address.  
   Note this is internally prefixed with the hostname in square brackets.  
   Possible levels in configuration file: Global, Project, Repository, Ref-Id.  
   Example value: `Webhook script <noreply@example.com>`

- `reply_to_mail` (String, required on Global level)  
   Mail address to use in the `Reply-to` header.  
   Usually a support address.  
   Possible levels in configuration file: Global, Project, Repository, Ref-Id.  
   Example value: `support@example.com`

- `secret` (String, required on Repository level)  
   Secret configured in Bitbucket, used in HMAC validation of the JSON webhook.  
   Example value: `s0m3Th1n6_v3rY_$ecr3T`

- `commands` (Array of strings, required on Ref-id level)  
   The actual commands to execute. These are executed via [PHP exec](https://www.php.net/manual/de/function.exec.php),
   i.e. using a shell, after appending `2>&1` to catch all output into a single buffer.
   The exit codes are checked and reported for each command, and `continue_on_error` decides whether execution is aborted
   when one command in the array fails.
   Example value: `[ "echo Hello", "ssh somehost.example.com doSomething" ]`

#### Common use cases

Common use cases will involve triggering other webhooks, or executing commands on other machines via `ssh`.
Note that for the latter use case, you should consider basic safety measures, i.e. firewalling, login only with SSH keys, limit the commands
which can be executed on the host which is accessed etc.


## Disclaimer

This code is not affiliated with [Atlassian](https://www.atlassian.com/) or [GitLab](https://about.gitlab.com/) in any way.
