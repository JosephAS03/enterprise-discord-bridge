<?php
if (!defined('ABSPATH')) exit;

function edb_register_plugin_update_checker(): void {
  $config = require EDB_PLUGIN_DIR . 'includes/update-checker-config.php';
  if (!is_array($config)) return;
  $config = apply_filters('edb_update_checker_config', $config, EDB_PLUGIN_FILE);
  $repository = trim((string)($config['repository'] ?? ''));
  if ($repository === '') return;

  $factory = 'YahnisElsts\\PluginUpdateChecker\\v6\\PucFactory';
  if (!class_exists($factory, false)) {
    require_once EDB_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
  }
  if (!class_exists($factory, false)) return;

  $checker = $factory::buildUpdateChecker($repository, EDB_PLUGIN_FILE, 'enterprise-discord-bridge');
  $branch = trim((string)($config['branch'] ?? ''));
  if ($branch !== '') $checker->setBranch($branch);

  $token_constant = (string)($config['token_constant'] ?? '');
  $token = $token_constant !== '' && defined($token_constant) ? (string)constant($token_constant) : '';
  $token = (string)apply_filters('edb_update_checker_github_token', $token, EDB_PLUGIN_FILE);
  if ($token !== '') $checker->setAuthentication($token);
}

add_action('plugins_loaded', 'edb_register_plugin_update_checker', 1);
