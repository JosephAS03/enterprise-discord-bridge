<?php
if (!defined('ABSPATH')) exit;

return [
  // Add the GitHub repository URL once the repository has been created.
  'repository' => '',
  // Leave blank to use the repository's default branch and tags.
  'branch' => '',
  // Define this token in wp-config.php only if the repository is private.
  'token_constant' => 'EDB_UPDATE_GITHUB_TOKEN',
];
