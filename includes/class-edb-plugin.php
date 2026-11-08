<?php
if (!defined('ABSPATH')) {
    exit;
}

class EDB_Plugin {
    const OPTION_KEY = 'edb_settings';
    const RESET_SECRET_OPTION = 'edb_bot_shared_secret';
    const NONCE_ACTION = 'edb_action';
    const CRON_HOOK = 'edb_dynamic_role_sync_event';
    const GUILD_CACHE_META = 'edb_discord_guilds';
    const GUILD_CACHE_AT_META = 'edb_discord_guilds_refreshed_at';
    const PRIMARY_ROLE_NAMES_META = 'edb_discord_primary_role_names';
    const PRIMARY_ROLE_IDS_META = 'edb_discord_primary_role_ids';
    const PRIMARY_ROLE_SYNC_AT_META = 'edb_discord_primary_role_sync_at';
    const PRIMARY_ROLE_SYNC_ERROR_META = 'edb_discord_primary_role_sync_error';
    const USER_META_LAST_PORTAL_LOGIN_AT = 'edb_portal_last_login_at';
    const USER_META_LAST_PORTAL_LOGIN_REMINDER_AT = 'edb_portal_last_login_reminder_at';
    const USER_META_LAST_PORTAL_EMAIL_REMINDER_AT = 'edb_portal_last_email_reminder_at';
    const OPTION_DM_QUEUE = 'edb_dm_queue';
    const OPTION_SYNC_QUEUE = 'edb_sync_queue';
    const META_ATTENTION_NOTIFIED_USER_IDS = '_edb_discord_attention_notified_user_ids';

    protected static $instance = null;
    protected $role_cache = array();
    protected $guild_member_id_cache = array();

    public static function instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function activate() {
        self::instance()->ensure_cron_schedule();
        flush_rewrite_rules(false);
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        flush_rewrite_rules(false);
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_front_assets'));
        add_action('show_user_profile', array($this, 'render_user_fields'));
        add_action('edit_user_profile', array($this, 'render_user_fields'));
        add_action('personal_options_update', array($this, 'save_user_fields'));
        add_action('edit_user_profile_update', array($this, 'save_user_fields'));
        add_action('profile_update', array($this, 'trigger_portal_user_sync'), 20, 2);
        add_action('user_register', array($this, 'trigger_portal_user_sync'), 20, 1);
        add_action('set_user_role', array($this, 'trigger_portal_user_sync_from_role'), 20, 3);
        add_action('set_object_terms', array($this, 'queue_sync_for_user_taxonomy'), 20, 6);
        add_action('wp_login', array($this, 'trigger_portal_user_sync_on_login'), 20, 2);
        add_action('eum_profile_change_applied', array($this, 'trigger_portal_user_sync_from_profile_change'), 20, 2);
        add_action('eum_user_hire_applied', array($this, 'trigger_portal_user_sync_from_hire'), 20, 3);
        add_action('eum_leave_request_created', array($this, 'handle_leave_attention_created'), 20, 3);
        add_action('eum_leave_status_changed', array($this, 'handle_attention_status_changed'), 20, 4);
        add_action('eum_availability_request_created', array($this, 'handle_availability_attention_created'), 20, 4);
        add_action('eum_availability_status_changed', array($this, 'handle_attention_status_changed'), 20, 4);
        add_action('eum_notification_created', array($this, 'handle_portal_notification_created'), 20, 3);
        add_action('eum_activity_logged', array($this, 'handle_portal_activity_logged'), 20, 2);
        add_action('save_post', array($this, 'queue_sync_for_portal_post'), 20, 3);
        add_action('updated_option', array($this, 'queue_sync_for_portal_option_update'), 20, 3);
        add_action('added_option', array($this, 'queue_sync_for_portal_option_add'), 20, 2);
        add_action('deleted_option', array($this, 'queue_sync_for_portal_option_delete'), 20, 1);

        add_shortcode('eum_discord_account', array($this, 'shortcode_account'));
        add_shortcode('eum_discord_console', array($this, 'shortcode_message_center'));
        add_shortcode('eum_discord_message_center', array($this, 'shortcode_message_center'));
        add_shortcode('eum_discord_password_reset', array($this, 'shortcode_password_reset'));
        add_shortcode('eum_discord_bulk_editor', array($this, 'shortcode_bulk_editor_removed'));

        add_action('init', array($this, 'handle_oauth_requests'));
        add_action('init', array($this, 'handle_post_actions'));
        add_action('init', array($this, 'ensure_cron_schedule'));
        add_action('template_redirect', array($this, 'handle_password_reset_landing'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action(self::CRON_HOOK, array($this, 'run_queued_syncs'), 5);
        add_action(self::CRON_HOOK, array($this, 'run_dynamic_role_sync'));
        add_action(self::CRON_HOOK, array($this, 'run_automation_pass'), 20);
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        add_filter('eum_allow_public_request', array($this, 'allow_public_password_reset_request'));
    }

    public function add_cron_schedules($schedules) {
        if (empty($schedules['edb_five_minutes'])) {
            $schedules['edb_five_minutes'] = array(
                'interval' => 300,
                'display' => 'Every 5 Minutes (Fallback)',
            );
        }
        return $schedules;
    }

    public function settings() {
        $defaults = array(
            'client_id' => '',
            'client_secret' => '',
            'bot_token' => '',
            'guild_id' => '',
            'redirect_uri' => home_url('/?edb_discord_oauth=callback'),
            'oauth_scopes' => 'identify email guilds guilds.join',
            'allow_login' => 1,
            'auto_link_by_email' => 0,
            'auto_join_primary_guild' => 1,
            'auto_sync_on_link' => 1,
            'dm_prefix_sender' => 1,
            'activity_log_channel_id' => '',
            'bot_service_url' => 'https://jasentinternal.wispbyte.app',
            'password_reset_page_url' => home_url('/?edb_discord_password_reset=1'),
            'primary_role_maps_text' => '',
            'role_rules_json' => "[]",
            'secondary_guild_rules_json' => "[]",
            'auto_create_primary_roles' => 1,
            'primary_role_authenticated' => 'Authenticated Portal User',
            'primary_role_community' => 'Community',
            'primary_role_alumni' => 'JAS Enterprises Alumni',
            'primary_role_clocked_in' => 'Clocked In',
            'primary_role_scheduled_now' => 'Scheduled Now',
            'primary_role_job_path_prefix' => 'Path: ',
            'primary_role_group_prefix' => 'Group: ',
            'primary_role_project_prefix' => 'Project: ',
        );
        return wp_parse_args(get_option(self::OPTION_KEY, array()), $defaults);
    }

    public function ensure_cron_schedule() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 120, 'edb_five_minutes', self::CRON_HOOK);
        }
    }

    public function admin_menu() {
        add_menu_page('Discord Bridge', 'Discord Bridge', 'manage_options', 'edb-discord', array($this, 'settings_page'), 'dashicons-format-chat', 58);
        add_submenu_page('edb-discord', 'Settings', 'Settings', 'manage_options', 'edb-discord', array($this, 'settings_page'));
        add_submenu_page('edb-discord', 'Message Center', 'Message Center', 'manage_options', 'edb-discord-message', array($this, 'message_page'));
        add_submenu_page('edb-discord', 'Role Sync', 'Role Sync', 'manage_options', 'edb-discord-sync', array($this, 'role_sync_page'));
        add_submenu_page('edb-discord', 'ID References', 'ID References', 'manage_options', 'edb-discord-ids', array($this, 'id_references_page'));
        add_submenu_page('edb-discord', 'Guild Directory', 'Guild Directory', 'manage_options', 'edb-discord-guilds', array($this, 'guild_directory_page'));
    }

    public function register_settings() {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, array($this, 'sanitize_settings'));
    }

    public function sanitize_settings($input) {
        $old = $this->settings();
        $out = $old;
        $out['client_id'] = sanitize_text_field(isset($input['client_id']) ? $input['client_id'] : '');
        $out['client_secret'] = sanitize_text_field(isset($input['client_secret']) ? $input['client_secret'] : '');
        $out['bot_token'] = sanitize_text_field(isset($input['bot_token']) ? $input['bot_token'] : '');
        $out['guild_id'] = sanitize_text_field(isset($input['guild_id']) ? $input['guild_id'] : '');
        $out['redirect_uri'] = esc_url_raw(isset($input['redirect_uri']) ? $input['redirect_uri'] : '');
        $out['oauth_scopes'] = sanitize_text_field(isset($input['oauth_scopes']) ? $input['oauth_scopes'] : 'identify email guilds guilds.join');
        $out['allow_login'] = empty($input['allow_login']) ? 0 : 1;
        $out['auto_link_by_email'] = empty($input['auto_link_by_email']) ? 0 : 1;
        $out['auto_join_primary_guild'] = empty($input['auto_join_primary_guild']) ? 0 : 1;
        $out['auto_sync_on_link'] = empty($input['auto_sync_on_link']) ? 0 : 1;
        $out['dm_prefix_sender'] = empty($input['dm_prefix_sender']) ? 0 : 1;
        $out['activity_log_channel_id'] = sanitize_text_field(isset($input['activity_log_channel_id']) ? $input['activity_log_channel_id'] : '');
        $out['bot_service_url'] = esc_url_raw(isset($input['bot_service_url']) ? $input['bot_service_url'] : 'https://jasentinternal.wispbyte.app');
        $out['password_reset_page_url'] = esc_url_raw(isset($input['password_reset_page_url']) ? $input['password_reset_page_url'] : home_url('/?edb_discord_password_reset=1'));
        $out['primary_role_maps_text'] = $this->sanitize_role_map_text(isset($input['primary_role_maps_text']) ? wp_unslash($input['primary_role_maps_text']) : '');
        $out['role_rules_json'] = $this->sanitize_json_blob(isset($input['role_rules_json']) ? wp_unslash($input['role_rules_json']) : '[]');
        $out['secondary_guild_rules_json'] = $this->sanitize_json_blob(isset($input['secondary_guild_rules_json']) ? wp_unslash($input['secondary_guild_rules_json']) : '[]');
        $out['auto_create_primary_roles'] = empty($input['auto_create_primary_roles']) ? 0 : 1;
        $out['primary_role_authenticated'] = sanitize_text_field(isset($input['primary_role_authenticated']) ? $input['primary_role_authenticated'] : 'Authenticated Portal User');
        $out['primary_role_community'] = sanitize_text_field(isset($input['primary_role_community']) ? $input['primary_role_community'] : 'Community');
        $out['primary_role_alumni'] = sanitize_text_field(isset($input['primary_role_alumni']) ? $input['primary_role_alumni'] : 'JAS Enterprises Alumni');
        $out['primary_role_clocked_in'] = sanitize_text_field(isset($input['primary_role_clocked_in']) ? $input['primary_role_clocked_in'] : 'Clocked In');
        $out['primary_role_scheduled_now'] = sanitize_text_field(isset($input['primary_role_scheduled_now']) ? $input['primary_role_scheduled_now'] : 'Scheduled Now');
        $out['primary_role_job_path_prefix'] = sanitize_text_field(isset($input['primary_role_job_path_prefix']) ? $input['primary_role_job_path_prefix'] : 'Path: ');
        $out['primary_role_group_prefix'] = sanitize_text_field(isset($input['primary_role_group_prefix']) ? $input['primary_role_group_prefix'] : 'Group: ');
        $out['primary_role_project_prefix'] = sanitize_text_field(isset($input['primary_role_project_prefix']) ? $input['primary_role_project_prefix'] : 'Project: ');

        if (!empty($input['bot_shared_secret'])) {
            update_option(self::RESET_SECRET_OPTION, sanitize_text_field($input['bot_shared_secret']));
        }

        return $out;
    }

    protected function sanitize_role_map_text($raw) {
        $raw = str_replace(array("\r\n", "\r"), "\n", (string) $raw);
        return sanitize_textarea_field($raw);
    }

    protected function sanitize_json_blob($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '[]';
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return '[]';
        }
        return wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function enqueue_admin_assets($hook) {
        if (strpos((string) $hook, 'edb-discord') === false && $hook !== 'profile.php' && $hook !== 'user-edit.php') {
            return;
        }
        wp_enqueue_style('edb-ui', EDB_PLUGIN_URL . 'assets/edb-ui.css', array(), '0.4.0');
        wp_enqueue_script('edb-ui', EDB_PLUGIN_URL . 'assets/edb-ui.js', array(), '0.4.0', true);
    }

    public function enqueue_front_assets() {
        wp_enqueue_style('edb-ui', EDB_PLUGIN_URL . 'assets/edb-ui.css', array(), '0.4.0');
        wp_enqueue_script('edb-ui', EDB_PLUGIN_URL . 'assets/edb-ui.js', array(), '0.4.0', true);
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        $settings = $this->settings();
        $secret = get_option(self::RESET_SECRET_OPTION, '');
        if ($secret === '') {
            $secret = wp_generate_password(48, false, false);
            update_option(self::RESET_SECRET_OPTION, $secret);
        }
        $notice = isset($_GET['edb_notice']) ? sanitize_text_field(wp_unslash($_GET['edb_notice'])) : '';
        echo '<div class="wrap edb-admin-shell">';
        echo '<div class="edb-pagehead"><div><h1>Discord Bridge</h1><p>OAuth, messaging, guild membership, password resets, and dynamic Discord role sync for your Enterprise portal.</p></div>';
        echo '<div class="edb-pagehead__actions"><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=edb-discord-message')) . '">Open Message Center</a><a class="button" href="' . esc_url(admin_url('admin.php?page=edb-discord-sync')) . '">Open Role Sync</a></div></div>';
        if ($notice) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notice) . '</p></div>';
        }
        echo '<form method="post" action="options.php">';
        settings_fields(self::OPTION_KEY);
        echo '<div class="edb-grid edb-grid--2">';

        echo '<div class="edb-card"><h2>OAuth & Bot</h2>';
        $this->settings_field('Client ID', '<input class="regular-text" name="' . esc_attr(self::OPTION_KEY) . '[client_id]" value="' . esc_attr($settings['client_id']) . '">');
        $this->settings_field('Client Secret', '<input class="regular-text" name="' . esc_attr(self::OPTION_KEY) . '[client_secret]" value="' . esc_attr($settings['client_secret']) . '">');
        $this->settings_field('Bot Token', '<input class="regular-text" name="' . esc_attr(self::OPTION_KEY) . '[bot_token]" value="' . esc_attr($settings['bot_token']) . '">');
        $this->settings_field('Primary Guild ID', '<input class="regular-text" name="' . esc_attr(self::OPTION_KEY) . '[guild_id]" value="' . esc_attr($settings['guild_id']) . '">');
        $this->settings_field('Redirect URI', '<input class="regular-text" name="' . esc_attr(self::OPTION_KEY) . '[redirect_uri]" value="' . esc_attr($settings['redirect_uri']) . '">');
        $this->settings_field('OAuth Scopes', '<input class="regular-text" name="' . esc_attr(self::OPTION_KEY) . '[oauth_scopes]" value="' . esc_attr($settings['oauth_scopes']) . '"><p class="description">The portal always requests identify and guilds.join. Add optional scopes such as email or guilds here. Users must approve guilds.join before the bot can restore their server membership.</p>');
        $this->settings_field('Bot Shared Secret', '<input class="regular-text" name="' . esc_attr(self::OPTION_KEY) . '[bot_shared_secret]" value="' . esc_attr($secret) . '"><p class="description">Used by your external bot service when it calls the secure REST endpoints.</p>');
        $this->settings_field('Bot Service URL', '<input class="regular-text" name="' . esc_attr(self::OPTION_KEY) . '[bot_service_url]" value="' . esc_attr($settings['bot_service_url']) . '" placeholder="https://jasentinternal.wispbyte.app"><p class="description">WordPress calls this bot service for Discord delivery before falling back to direct Discord API calls.</p>');
        echo '</div>';

        echo '<div class="edb-card"><h2>Portal Behavior</h2>';
        $this->settings_field('Allow Discord Login', '<label><input type="checkbox" name="' . esc_attr(self::OPTION_KEY) . '[allow_login]" value="1" ' . checked(1, $settings['allow_login'], false) . '> Allow a linked Discord account to sign into WordPress</label>');
        $this->settings_field('Auto-link by Email', '<label><input type="checkbox" name="' . esc_attr(self::OPTION_KEY) . '[auto_link_by_email]" value="1" ' . checked(1, $settings['auto_link_by_email'], false) . '> Link first login if Discord email matches exactly one WordPress user</label>');
        $this->settings_field('Auto-join Primary Guild', '<label><input type="checkbox" name="' . esc_attr(self::OPTION_KEY) . '[auto_join_primary_guild]" value="1" ' . checked(1, $settings['auto_join_primary_guild'], false) . '> Add newly authenticated users to the main guild automatically</label>');
        $this->settings_field('Auto-sync Roles on Link', '<label><input type="checkbox" name="' . esc_attr(self::OPTION_KEY) . '[auto_sync_on_link]" value="1" ' . checked(1, $settings['auto_sync_on_link'], false) . '> Run a role sync as soon as a user links Discord</label>');
        $this->settings_field('DM Prefix Sender', '<label><input type="checkbox" name="' . esc_attr(self::OPTION_KEY) . '[dm_prefix_sender]" value="1" ' . checked(1, $settings['dm_prefix_sender'], false) . '> Prefix outgoing DMs with the sender name and email</label>');
        $this->settings_field('Activity Log Channel ID', '<input class="regular-text" name="' . esc_attr(self::OPTION_KEY) . '[activity_log_channel_id]" value="' . esc_attr($settings['activity_log_channel_id']) . '"><p class="description">Bot and portal actions are posted to this Discord channel when it is set.</p>');
        $this->settings_field('Password Reset Page URL', '<input class="regular-text" name="' . esc_attr(self::OPTION_KEY) . '[password_reset_page_url]" value="' . esc_attr($settings['password_reset_page_url']) . '"><p class="description">A page where users start a Discord-authenticated password reset. The query-page fallback also works.</p>');
        echo '<div class="edb-callout"><strong>Useful URLs</strong><ul><li>OAuth callback: <code>' . esc_html(home_url('/?edb_discord_oauth=callback')) . '</code></li><li>Password reset start: <code>' . esc_html($this->password_reset_landing_url()) . '</code></li><li>Bot password reset endpoint: <code>' . esc_html($this->bridge_rest_url('/edb/v1/password-reset-command')) . '</code></li><li>Bot role sync endpoint: <code>' . esc_html($this->bridge_rest_url('/edb/v1/role-sync')) . '</code></li><li>Bot guild refresh endpoint: <code>' . esc_html($this->bridge_rest_url('/edb/v1/guilds-refresh')) . '</code></li><li>Bot automation endpoint: <code>' . esc_html($this->bridge_rest_url('/edb/v1/automation-pass')) . '</code></li><li>Bot timeclock endpoint: <code>' . esc_html($this->bridge_rest_url('/edb/v1/timeclock-command')) . '</code></li><li>Bot profile endpoint: <code>' . esc_html($this->bridge_rest_url('/edb/v1/profile-command')) . '</code></li><li>Bot Discord permissions endpoint: <code>' . esc_html($this->bridge_rest_url('/edb/v1/discord-permissions-command')) . '</code></li><li>Bot attention endpoint: <code>' . esc_html($this->bridge_rest_url('/edb/v1/attention-command')) . '</code></li><li>Bot leave endpoint: <code>' . esc_html($this->bridge_rest_url('/edb/v1/leave-command')) . '</code></li><li>Bot availability endpoint: <code>' . esc_html($this->bridge_rest_url('/edb/v1/availability-command')) . '</code></li></ul></div>';
        echo '</div>';

        echo '<div class="edb-card"><h2>Primary Guild Auto Roles</h2>';
        $this->settings_field('Enable Auto-created Roles', '<label><input type="checkbox" name="' . esc_attr(self::OPTION_KEY) . '[auto_create_primary_roles]" value="1" ' . checked(1, $settings['auto_create_primary_roles'], false) . '> Create and manage roles automatically in the primary guild</label>');
        $this->settings_field('Authenticated Role', '<input class="regular-text" name="' . esc_attr(self::OPTION_KEY) . '[primary_role_authenticated]" value="' . esc_attr($settings['primary_role_authenticated']) . '">');
        $this->settings_field('Community Role', '<input class="regular-text" name="' . esc_attr(self::OPTION_KEY) . '[primary_role_community]" value="' . esc_attr($settings['primary_role_community']) . '">');
        $this->settings_field('Alumni Role', '<input class="regular-text" name="' . esc_attr(self::OPTION_KEY) . '[primary_role_alumni]" value="' . esc_attr($settings['primary_role_alumni']) . '">');
        $this->settings_field('Clocked In Role', '<input class="regular-text" name="' . esc_attr(self::OPTION_KEY) . '[primary_role_clocked_in]" value="' . esc_attr($settings['primary_role_clocked_in']) . '">');
        $this->settings_field('Scheduled Now Role', '<input class="regular-text" name="' . esc_attr(self::OPTION_KEY) . '[primary_role_scheduled_now]" value="' . esc_attr($settings['primary_role_scheduled_now']) . '">');
        $this->settings_field('Path ID Role Prefix', '<input class="regular-text" name="' . esc_attr(self::OPTION_KEY) . '[primary_role_job_path_prefix]" value="' . esc_attr($settings['primary_role_job_path_prefix']) . '">');
        $this->settings_field('Group ID Role Prefix', '<input class="regular-text" name="' . esc_attr(self::OPTION_KEY) . '[primary_role_group_prefix]" value="' . esc_attr($settings['primary_role_group_prefix']) . '">');
        $this->settings_field('Project ID Role Prefix', '<input class="regular-text" name="' . esc_attr(self::OPTION_KEY) . '[primary_role_project_prefix]" value="' . esc_attr($settings['primary_role_project_prefix']) . '">');
        echo '<p class="description">Path, group, and project roles are auto-created by portal ID, such as Path: 81, Group: 42, and Project: 64. Existing Discord roles with those exact names are reused. The Discord Connected and Discord Not Connected groups sync to roles with those exact names.</p>';
        echo '</div>';

        echo '<div class="edb-card edb-card--wide"><h2>Easy Primary Guild Role Maps</h2>';
        echo '<p class="description">Optional overrides for existing Discord role IDs. Use portal IDs or slugs on the left and Discord role IDs on the right. Auto ID roles above do not require entries here.</p>';
        echo '<textarea name="' . esc_attr(self::OPTION_KEY) . '[primary_role_maps_text]" rows="13" class="large-text code">' . esc_textarea($settings['primary_role_maps_text']) . '</textarea>';
        echo '<div class="edb-codehint"><pre>' . esc_html("Role: administrator={123456789012345678}\nGroup: 42={234567890123456789}\nPath: 81={345678901234567890}\nPath+Title: 81:eum_job_title:23={345678901234567891}\nProject: 64={456789012345678901}\nLocation: 17={567890123456789012}\nUser: wp:123={678901234567890123}\nUser: discord:111122223333444455={789012345678901234}\nEvent: 42:invited={890123456789012345}\nEvent: 42:accepted={901234567890123456}\nEvent: 42:declined={112233445566778899}") . '</pre></div>';
        echo '</div>';

        echo '<div class="edb-card"><h2>Manual Rules for Other Guilds</h2>';
        echo '<p class="description">Use existing role IDs in secondary guilds. Rules can target WordPress roles, EUM groups/projects, job path text, linked users, employment status, and current clock-in status.</p>';
        echo '<textarea name="' . esc_attr(self::OPTION_KEY) . '[secondary_guild_rules_json]" rows="16" class="large-text code">' . esc_textarea($settings['secondary_guild_rules_json']) . '</textarea>';
        echo '<div class="edb-codehint"><pre>' . esc_html("[\n  {\n    \"guild_id\": \"123456789012345678\",\n    \"role_id\": \"234567890123456789\",\n    \"wp_role\": \"manager\"\n  },\n  {\n    \"guild_id\": \"123456789012345678\",\n    \"role_id\": \"345678901234567890\",\n    \"employment_status\": \"terminated\"\n  }\n]") . '</pre></div>';
        echo '</div>';

        echo '<div class="edb-card edb-card--wide"><h2>Advanced Role Rules</h2>';
        echo '<p class="description">These rules apply role IDs directly, mainly for the primary guild. They work alongside the auto-created roles.</p>';
        echo '<textarea name="' . esc_attr(self::OPTION_KEY) . '[role_rules_json]" rows="16" class="large-text code">' . esc_textarea($settings['role_rules_json']) . '</textarea>';
        echo '<div class="edb-codehint"><pre>' . esc_html("[\n  {\n    \"role_id\": \"987654321098765432\",\n    \"wp_role\": \"administrator\"\n  },\n  {\n    \"role_id\": \"876543210987654321\",\n    \"job_path_contains\": \"Operations\",\n    \"exclude_if_terminated\": true\n  },\n  {\n    \"role_id\": \"765432109876543210\",\n    \"terminated_only\": true\n  }\n]") . '</pre></div>';
        echo '</div>';

        echo '</div>';
        submit_button('Save Discord Bridge Settings');
        echo '</form></div>';
    }

    protected function settings_field($label, $html) {
        echo '<div class="edb-field"><label class="edb-field__label">' . esc_html($label) . '</label><div class="edb-field__control">' . $html . '</div></div>';
    }

    public function message_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        echo '<div class="wrap edb-admin-shell">';
        echo '<div class="edb-pagehead"><div><h1>Discord Message Center</h1><p>Send direct messages, post to channels, refresh guild membership, and run Discord sync actions with a cleaner selector UI.</p></div></div>';
        echo $this->render_message_center(true);
        echo '</div>';
    }

    public function role_sync_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        $summary = $this->role_sync_summary();
        echo '<div class="wrap edb-admin-shell">';
        echo '<div class="edb-pagehead"><div><h1>Role Sync</h1><p>Preview what the bridge manages in Discord, including ID-mapped portal roles, and run a sync on demand.</p></div></div>';
        echo '<div class="edb-grid edb-grid--3">';
        echo $this->metric_card('Linked Accounts', (string) $summary['linked_users']);
        echo $this->metric_card('Users with Guild Cache', (string) $summary['guild_cached_users']);
        echo $this->metric_card('Primary Guild Auto Roles', (string) $summary['auto_role_labels']);
        echo $this->metric_card('Role Sync Errors', (string) $summary['sync_errors']);
        echo '</div>';
        echo '<div class="edb-grid edb-grid--2">';
        echo '<div class="edb-card"><h2>Run Sync</h2>';
        echo '<form method="post">';
        wp_nonce_field(self::NONCE_ACTION, 'edb_nonce');
        echo '<input type="hidden" name="edb_action" value="sync_all_roles">';
        echo '<p><button class="button button-primary">Sync Primary Guild Roles Now</button></p>';
        echo '</form>';
        echo '<form method="post">';
        wp_nonce_field(self::NONCE_ACTION, 'edb_nonce');
        echo '<input type="hidden" name="edb_action" value="refresh_all_guilds">';
        echo '<p><button class="button">Refresh All Stored Guild Lists</button></p>';
        echo '</form>';
        echo '<p class="description">The Discord bot now drives the main automation pass every 30 minutes. WordPress keeps a five-minute cron fallback so Community membership and queued sync work do not stall if the bot is offline.</p>';
        echo '</div>';
        echo '<div class="edb-card"><h2>Managed Role Types</h2><ul class="edb-bulletless">';
        foreach ($this->primary_auto_role_labels() as $label) {
            echo '<li><span class="edb-pill edb-pill--soft">' . esc_html($label) . '</span></li>';
        }
        echo '</ul><p class="description">Plus any role IDs matched by easy maps, primary-guild JSON rules, and any manual mappings for secondary guilds.</p></div>';
        echo '</div>';
        echo '</div>';
    }

    public function id_references_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        echo '<div class="wrap edb-admin-shell">';
        echo '<div class="edb-pagehead"><div><h1>ID References</h1><p>Copy the portal and Discord IDs used by Discord role maps, auto-created ID roles, event RSVP roles, and direct user mappings.</p></div>';
        echo '<div class="edb-pagehead__actions"><a class="button" href="' . esc_url(admin_url('admin.php?page=edb-discord')) . '">Open Settings</a><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=edb-discord-sync')) . '">Open Role Sync</a></div></div>';
        echo '<div class="edb-card edb-card--wide"><h2>Usage Patterns</h2>';
        echo '<div class="edb-codehint"><pre>' . esc_html("Auto-created role names:\nPath: {path-id}\nGroup: {group-id}\nProject: {project-id}\n\nEasy role map overrides:\nPath: {path-id}={discord-role-id}\nPath+Title: {path-id}:eum_job_title:{title-id}={discord-role-id}\nGroup: {group-id}={discord-role-id}\nProject: {project-id}={discord-role-id}\nLocation: {location-id}={discord-role-id}\nRole: administrator={discord-role-id}\nUser: wp:{user-id}={discord-role-id}\nUser: discord:{discord-user-id}={discord-role-id}\nEvent: {event-id}:accepted={discord-role-id}") . '</pre></div>';
        echo '</div>';
        echo '<div class="edb-grid edb-grid--2">';
        $this->render_id_reference_table('Job Path IDs', $this->id_reference_term_rows('eum_job_path', 'Path', true, true), array('ID', 'Name / Path', 'Auto Role', 'Easy Map Key'));
        $this->render_id_reference_table('Path + Title IDs', $this->id_reference_path_title_rows(), array('Path ID', 'Path + Title', 'Title ID / Token', 'Easy Map Key'));
        $this->render_id_reference_table('Group IDs', $this->id_reference_term_rows('eum_user_group', 'Group', false, true), array('ID', 'Name', 'Auto Role', 'Easy Map Key'));
        $this->render_id_reference_table('Project IDs', $this->id_reference_term_rows('eum_project', 'Project', false, true), array('ID', 'Name', 'Auto Role', 'Easy Map Key'));
        $this->render_id_reference_table('Location IDs', $this->id_reference_term_rows('eum_location', 'Location', true, false), array('ID', 'Name / Path', 'Auto Role', 'Easy Map Key'));
        $this->render_id_reference_table('WordPress Roles', $this->id_reference_wp_role_rows(), array('ID / Slug', 'Name', 'Auto Role', 'Easy Map Key'));
        $this->render_id_reference_table('Users', $this->id_reference_user_rows(), array('ID', 'User', 'Discord ID', 'Easy Map Keys'));
        $this->render_id_reference_table('Events', $this->id_reference_event_rows(), array('ID', 'Event', 'Date', 'Easy Map Keys'));
        $this->render_id_reference_table('Primary Guild Discord Roles', $this->id_reference_discord_role_rows(), array('Discord Role ID', 'Role Name', 'Easy Map Value', 'Notes'));
        echo '</div>';
        echo '</div>';
    }

    protected function render_id_reference_table($title, $rows, $headers) {
        echo '<div class="edb-card edb-card--wide"><h2>' . esc_html($title) . '</h2>';
        if (empty($rows)) {
            echo '<p class="description">No references found for this section.</p></div>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr>';
        foreach ($headers as $header) {
            echo '<th>' . esc_html($header) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                $cell = (string) $cell;
                $is_code = strpos($cell, "\n") !== false || strpos($cell, ':') !== false || preg_match('/^[0-9]{4,}$/', $cell);
                echo '<td>' . ($is_code ? '<code style="white-space:pre-wrap">' . esc_html($cell) . '</code>' : esc_html($cell)) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    protected function id_reference_term_rows($taxonomy, $label, $hierarchical = false, $has_auto_role = false) {
        if (!taxonomy_exists($taxonomy)) {
            return array();
        }
        $terms = get_terms(array(
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
            'number' => 0,
        ));
        if (!is_array($terms) || is_wp_error($terms)) {
            return array();
        }
        $rows = array();
        foreach ($terms as $term) {
            if (!is_object($term) || empty($term->term_id)) {
                continue;
            }
            $term_id = (int) $term->term_id;
            $name = $hierarchical ? $this->term_full_path_label($taxonomy, $term_id) : (string) $term->name;
            $auto_role = $has_auto_role ? $label . ': ' . $term_id : 'N/A';
            $rows[] = array(
                (string) $term_id,
                $name !== '' ? $name : (string) $term->name,
                $auto_role,
                $label . ': {' . $term_id . '}={discord-role-id}',
            );
        }
        return $rows;
    }

    protected function id_reference_path_title_rows() {
        if (!taxonomy_exists('eum_job_path')) {
            return array();
        }
        $rows = array();
        $title_posts = array();
        if (class_exists('EUM_Job_Titles') && method_exists('EUM_Job_Titles', 'title_posts')) {
            $title_posts = EUM_Job_Titles::title_posts();
        } elseif (post_type_exists('eum_job_title')) {
            $title_posts = get_posts(array(
                'post_type' => 'eum_job_title',
                'post_status' => array('publish', 'draft'),
                'numberposts' => 5000,
                'orderby' => 'title',
                'order' => 'ASC',
                'suppress_filters' => false,
            ));
        }
        if (!is_array($title_posts) || empty($title_posts)) {
            return array();
        }
        foreach ($title_posts as $post) {
            if (!is_object($post) || empty($post->ID)) {
                continue;
            }
            $title_id = (int) $post->ID;
            $title_label = class_exists('EUM_Job_Titles') && method_exists('EUM_Job_Titles', 'title_label')
                ? (string) EUM_Job_Titles::title_label($title_id)
                : sanitize_text_field((string) get_the_title($post));
            if ($title_label === '') {
                continue;
            }
            $allowed_paths = class_exists('EUM_Job_Titles') && method_exists('EUM_Job_Titles', 'allowed_job_paths')
                ? EUM_Job_Titles::allowed_job_paths($title_id)
                : (array) get_post_meta($title_id, 'eum_allowed_job_paths', true);
            $allowed_paths = array_values(array_unique(array_filter(array_map('intval', (array) $allowed_paths))));
            foreach ($allowed_paths as $path_id) {
                $term = get_term((int) $path_id, 'eum_job_path');
                if (!$term || is_wp_error($term)) {
                    continue;
                }
                $path_label = $this->term_full_path_label('eum_job_path', (int) $path_id);
                if ($path_label === '') {
                    $path_label = (string) $term->name;
                }
                $token = class_exists('EUM_Job_Titles') && method_exists('EUM_Job_Titles', 'token_from_id')
                    ? (string) EUM_Job_Titles::token_from_id($title_id)
                    : 'eum_job_title:' . $title_id;
                $rows[] = array(
                    (string) $path_id,
                    $path_label . ' / ' . $title_label,
                    (string) $title_id . "\n" . $token,
                    'Path+Title: ' . $path_id . ':' . $title_id . '={discord-role-id}' . "\n" . 'Path+Title: ' . $path_id . ':' . $token . '={discord-role-id}',
                );
            }
        }
        usort($rows, function($a, $b) {
            $path_compare = strcasecmp((string) $a[1], (string) $b[1]);
            if ($path_compare !== 0) {
                return $path_compare;
            }
            return ((int) $a[0]) <=> ((int) $b[0]);
        });
        return $rows;
    }

    protected function id_reference_wp_role_rows() {
        $roles = wp_roles();
        $rows = array();
        foreach ((array) $roles->roles as $slug => $role) {
            $name = isset($role['name']) ? translate_user_role($role['name']) : $this->format_wp_role_label($slug);
            $rows[] = array(
                (string) $slug,
                (string) $name,
                'N/A',
                'Role: ' . (string) $slug . '={discord-role-id}',
            );
        }
        return $rows;
    }

    protected function id_reference_user_rows() {
        $rows = array();
        foreach (get_users(array('orderby' => 'display_name', 'order' => 'ASC', 'number' => 500)) as $user) {
            $discord_id = (string) get_user_meta($user->ID, 'edb_discord_user_id', true);
            $keys = 'User: wp:' . (int) $user->ID . '={discord-role-id}';
            if ($discord_id !== '') {
                $keys .= "\n" . 'User: discord:' . $discord_id . '={discord-role-id}';
            }
            $rows[] = array(
                (string) $user->ID,
                $user->display_name . ' <' . $user->user_email . '>',
                $discord_id !== '' ? $discord_id : 'Not linked',
                $keys,
            );
        }
        return $rows;
    }

    protected function id_reference_event_rows() {
        $post_type = class_exists('EUM_Events') && defined('EUM_Events::CPT') ? constant('EUM_Events::CPT') : 'eum_event';
        if (!post_type_exists($post_type)) {
            return array();
        }
        $events = get_posts(array(
            'post_type' => $post_type,
            'post_status' => 'any',
            'posts_per_page' => 300,
            'orderby' => 'date',
            'order' => 'DESC',
        ));
        $rows = array();
        foreach ($events as $event) {
            $event_id = (int) $event->ID;
            $start = (int) get_post_meta($event_id, class_exists('EUM_Events') && defined('EUM_Events::META_START_UTC') ? constant('EUM_Events::META_START_UTC') : '_eum_event_start_utc', true);
            $date = $start > 0 ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $start) : get_the_date('', $event);
            $rows[] = array(
                (string) $event_id,
                get_the_title($event),
                $date,
                'Event: ' . $event_id . ':invited={discord-role-id}' . "\n" . 'Event: ' . $event_id . ':accepted={discord-role-id}' . "\n" . 'Event: ' . $event_id . ':declined={discord-role-id}',
            );
        }
        return $rows;
    }

    protected function id_reference_discord_role_rows() {
        $settings = $this->settings();
        $guild_id = isset($settings['guild_id']) ? (string) $settings['guild_id'] : '';
        if ($guild_id === '') {
            return array(array('Missing primary guild ID', 'Configure Primary Guild ID first', '{discord-role-id}', 'Settings > OAuth & Bot'));
        }
        $rows = array();
        foreach ($this->get_guild_roles($guild_id) as $role) {
            if (empty($role['id']) || !isset($role['name']) || (string) $role['name'] === '@everyone') {
                continue;
            }
            $rows[] = array(
                (string) $role['id'],
                (string) $role['name'],
                '{' . (string) $role['id'] . '}',
                !empty($role['managed']) ? 'Managed by Discord integration' : '',
            );
        }
        return $rows;
    }

    public function guild_directory_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        $linked = $this->get_linked_users();
        echo '<div class="wrap edb-admin-shell">';
        echo '<div class="edb-pagehead"><div><h1>Guild Directory</h1><p>All authenticated Discord users and the guilds the bridge has stored for them.</p></div></div>';
        echo '<div class="edb-card">';
        if (empty($linked)) {
            echo '<p>No linked users yet.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>User</th><th>Discord</th><th>Guilds</th><th>Last Refreshed</th></tr></thead><tbody>';
            foreach ($linked as $user) {
                $guilds = $this->get_cached_guilds($user->ID);
                $refreshed = get_user_meta($user->ID, self::GUILD_CACHE_AT_META, true);
                echo '<tr>';
                echo '<td><strong>' . esc_html($user->display_name) . '</strong><br><span class="description">' . esc_html($user->user_email) . '</span></td>';
                echo '<td>' . esc_html($this->discord_label_for_user($user->ID)) . '</td>';
                echo '<td>';
                if (empty($guilds)) {
                    echo '<span class="description">No guild list cached yet.</span>';
                } else {
                    foreach ($guilds as $guild) {
                        echo '<span class="edb-pill">' . esc_html($guild['name'] . ' (' . $guild['id'] . ')') . '</span> ';
                    }
                }
                echo '</td>';
                echo '<td>' . esc_html($refreshed ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $refreshed) : '—') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div></div>';
    }

    protected function metric_card($label, $value) {
        return '<div class="edb-card edb-card--metric"><div class="edb-metric__value">' . esc_html($value) . '</div><div class="edb-metric__label">' . esc_html($label) . '</div></div>';
    }

    protected function role_sync_summary() {
        $linked = $this->get_linked_users();
        $cached = 0;
        $errors = 0;
        foreach ($linked as $user) {
            if (!empty($this->get_cached_guilds($user->ID))) {
                $cached++;
            }
            if ((string) get_user_meta($user->ID, self::PRIMARY_ROLE_SYNC_ERROR_META, true) !== '') {
                $errors++;
            }
        }
        return array(
            'linked_users' => count($linked),
            'guild_cached_users' => $cached,
            'auto_role_labels' => count($this->primary_auto_role_labels()),
            'sync_errors' => $errors,
        );
    }

    protected function primary_auto_role_labels() {
        $settings = $this->settings();
        $labels = array();
        if (!empty($settings['primary_role_authenticated'])) {
            $labels[] = $settings['primary_role_authenticated'];
        }
        if (!empty($settings['primary_role_community'])) {
            $labels[] = $settings['primary_role_community'];
        }
        if (!empty($settings['primary_role_alumni'])) {
            $labels[] = $settings['primary_role_alumni'];
        }
        if (!empty($settings['primary_role_clocked_in'])) {
            $labels[] = $settings['primary_role_clocked_in'];
        }
        if (!empty($settings['primary_role_scheduled_now'])) {
            $labels[] = $settings['primary_role_scheduled_now'];
        }
        if (!empty($settings['primary_role_job_path_prefix'])) {
            $labels[] = $settings['primary_role_job_path_prefix'] . '{path id}';
        }
        if (!empty($settings['primary_role_group_prefix'])) {
            $labels[] = $settings['primary_role_group_prefix'] . '{group id}';
        }
        if (!empty($settings['primary_role_project_prefix'])) {
            $labels[] = $settings['primary_role_project_prefix'] . '{project id}';
        }
        $map_count = count($this->primary_role_map_rows());
        if ($map_count > 0) {
            $labels[] = 'Easy role maps: ' . $map_count;
        }
        return $labels;
    }

    public function render_user_fields($user) {
        if (!current_user_can('edit_user', $user->ID)) {
            return;
        }
        $discord = $this->get_user_discord_meta($user->ID);
        $guilds = $this->get_cached_guilds($user->ID);
        echo '<div class="edb-profile-card"><h2>Discord Bridge</h2>';
        echo '<p class="description">Linked account details, cached guild membership, and synced role status.</p>';
        echo '<table class="form-table">';
        $this->profile_row('Discord User ID', '<input class="regular-text" name="edb_discord_user_id" value="' . esc_attr($discord['discord_user_id']) . '">');
        $this->profile_row('Discord Username', '<input class="regular-text" name="edb_discord_username" value="' . esc_attr($discord['discord_username']) . '">');
        $this->profile_row('Discord Global Name', '<input class="regular-text" name="edb_discord_global_name" value="' . esc_attr($discord['discord_global_name']) . '">');
        $this->profile_row('Discord Linked', '<label><input type="checkbox" name="edb_discord_linked" value="1" ' . checked(1, (int) $discord['discord_linked'], false) . '> Mark as linked</label>');
        $guild_html = '';
        if (empty($guilds)) {
            $guild_html = '<span class="description">No cached guild list yet.</span>';
        } else {
            foreach ($guilds as $guild) {
                $guild_html .= '<span class="edb-pill">' . esc_html($guild['name'] . ' (' . $guild['id'] . ')') . '</span> ';
            }
        }
        $this->profile_row('Cached Guilds', $guild_html);
        $this->profile_row('Primary Guild Roles', esc_html(implode(', ', (array) get_user_meta($user->ID, self::PRIMARY_ROLE_NAMES_META, true))));
        echo '</table></div>';
    }

    protected function profile_row($label, $field_html) {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $field_html . '</td></tr>';
    }

    public function save_user_fields($user_id) {
        if (!current_user_can('edit_user', $user_id)) {
            return;
        }
        update_user_meta($user_id, 'edb_discord_user_id', sanitize_text_field(isset($_POST['edb_discord_user_id']) ? wp_unslash($_POST['edb_discord_user_id']) : ''));
        update_user_meta($user_id, 'edb_discord_username', sanitize_text_field(isset($_POST['edb_discord_username']) ? wp_unslash($_POST['edb_discord_username']) : ''));
        update_user_meta($user_id, 'edb_discord_global_name', sanitize_text_field(isset($_POST['edb_discord_global_name']) ? wp_unslash($_POST['edb_discord_global_name']) : ''));
        update_user_meta($user_id, 'edb_discord_linked', empty($_POST['edb_discord_linked']) ? 0 : 1);
        $this->sync_portal_discord_group_status($user_id);
        $this->safe_run_single_user_sync($user_id);
    }

    public function trigger_portal_user_sync($user_id, $old_user_data = null) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return;
        }
        $this->queue_sync_request($user_id, 'portal_user_update');
        $this->safe_run_single_user_sync($user_id);
    }

    public function trigger_portal_user_sync_from_role($user_id, $role = '', $old_roles = array()) {
        $this->trigger_portal_user_sync($user_id);
    }

    public function queue_sync_for_user_taxonomy($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
        $taxonomy = sanitize_key((string) $taxonomy);
        if (!in_array($taxonomy, array('eum_user_group', 'eum_project', 'eum_job_path', 'eum_location'), true)) {
            return;
        }
        $user_id = (int) $object_id;
        if ($user_id > 0 && get_userdata($user_id)) {
            $is_group_backfill = class_exists('EUM_Discord_Groups')
                && method_exists('EUM_Discord_Groups', 'is_backfilling_users')
                && EUM_Discord_Groups::is_backfilling_users();
            if ($taxonomy === 'eum_user_group' && !$is_group_backfill && trim((string) get_user_meta($user_id, 'edb_discord_user_id', true)) !== '') {
                try {
                    if ($this->sync_user_roles($user_id)) {
                        return;
                    }
                } catch (Throwable $e) {
                    // Keep the queued sync below as a retry if an immediate Discord request fails.
                }
            }
            $this->queue_sync_request($user_id, 'portal_user_taxonomy_update');
        }
    }

    public function trigger_portal_user_sync_on_login($user_login, $user) {
        if ($user instanceof WP_User) {
            update_user_meta((int) $user->ID, self::USER_META_LAST_PORTAL_LOGIN_AT, current_time('mysql'));
            delete_user_meta((int) $user->ID, self::USER_META_LAST_PORTAL_LOGIN_REMINDER_AT);
            delete_user_meta((int) $user->ID, self::USER_META_LAST_PORTAL_EMAIL_REMINDER_AT);
            // Keep login responsive; the scheduled queue performs this role sync shortly afterward.
            $this->queue_sync_request((int) $user->ID, 'portal_user_login');
        }
    }

    public function trigger_portal_user_sync_from_profile_change($change_id, $target_user_id) {
        $this->trigger_portal_user_sync($target_user_id);
    }

    public function trigger_portal_user_sync_from_hire($user_id, $hire_id = 0, $context = array()) {
        $this->trigger_portal_user_sync($user_id);
    }

    public function queue_sync_for_portal_post($post_id, $post = null, $update = false) {
        $post_id = (int) $post_id;
        if ($post_id <= 0 || wp_is_post_revision($post_id)) {
            return;
        }
        if (!$post instanceof WP_Post) {
            $post = get_post($post_id);
        }
        if (!$post instanceof WP_Post) {
            return;
        }
        if (strpos((string) $post->post_type, 'eum_') !== 0) {
            return;
        }
        if ((string) $post->post_type === 'eum_notification') {
            return;
        }
        $this->queue_sync_request(0, 'portal_post_' . sanitize_key((string) $post->post_type), true);
    }

    public function queue_sync_for_portal_option_update($option, $old_value = null, $value = null) {
        if (!$this->is_sync_relevant_option((string) $option)) {
            return;
        }
        $this->queue_sync_request(0, 'portal_option_' . sanitize_key((string) $option), true);
    }

    public function queue_sync_for_portal_option_add($option, $value = null) {
        if (!$this->is_sync_relevant_option((string) $option)) {
            return;
        }
        $this->queue_sync_request(0, 'portal_option_' . sanitize_key((string) $option), true);
    }

    public function queue_sync_for_portal_option_delete($option) {
        if (!$this->is_sync_relevant_option((string) $option)) {
            return;
        }
        $this->queue_sync_request(0, 'portal_option_' . sanitize_key((string) $option), true);
    }

    protected function is_sync_relevant_option($option) {
        $option = sanitize_key((string) $option);
        if ($option === '') {
            return false;
        }
        if (in_array($option, array(self::OPTION_KEY, self::RESET_SECRET_OPTION, self::OPTION_DM_QUEUE, self::OPTION_SYNC_QUEUE), true)) {
            return false;
        }
        if (strpos($option, 'eum_schedule_') === 0 && substr($option, -9) === '_last_run') {
            return false;
        }
        return strpos($option, 'eum_') === 0 || strpos($option, 'edb_') === 0;
    }

    protected function queue_sync_request($user_id = 0, $context = 'portal', $full_sync = false) {
        $user_id = (int) $user_id;
        $context = sanitize_key((string) $context);
        $queue = get_option(self::OPTION_SYNC_QUEUE, array());
        $queue = is_array($queue) ? $queue : array();
        $queue['full_sync'] = !empty($queue['full_sync']) || !empty($full_sync);
        $queue['user_ids'] = array_values(array_unique(array_merge(
            array_map('intval', (array) ($queue['user_ids'] ?? array())),
            $user_id > 0 ? array($user_id) : array()
        )));
        $queue['contexts'] = array_values(array_unique(array_filter(array_merge(
            array_map('sanitize_key', (array) ($queue['contexts'] ?? array())),
            array($context)
        ))));
        $queue['updated_at'] = current_time('mysql');
        update_option(self::OPTION_SYNC_QUEUE, $queue, false);
        $this->kick_sync_queue_runner();

        $summary = $user_id > 0
            ? 'Portal user activity queued a Discord sync.'
            : 'Portal data activity queued a full Discord sync.';
        $this->log_configured_activity_message($this->format_activity_message('sync_queue', array(
            'summary' => $summary,
            'user_id' => $user_id,
            'details' => array(
                'context' => $context,
                'full_sync' => !empty($full_sync),
            ),
        )));
    }

    protected function pull_sync_queue() {
        $queue = get_option(self::OPTION_SYNC_QUEUE, array());
        delete_option(self::OPTION_SYNC_QUEUE);
        return is_array($queue) ? $queue : array();
    }

    protected function kick_sync_queue_runner() {
        $this->ensure_cron_schedule();
        if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')) {
            $soon = time() + 15;
            $next = wp_next_scheduled(self::CRON_HOOK);
            if ($next === false || (int) $next > ($soon + 45)) {
                wp_schedule_single_event($soon, self::CRON_HOOK);
            }
        }
        if (function_exists('spawn_cron')) {
            spawn_cron(time());
        }
    }

    public function run_queued_syncs() {
        return $this->process_sync_queue($this->pull_sync_queue());
    }

    protected function process_sync_queue($queue) {
        $queue = is_array($queue) ? $queue : array();
        $user_ids = array_values(array_unique(array_filter(array_map('absint', (array) ($queue['user_ids'] ?? array())))));
        $contexts = array_values(array_filter(array_map('sanitize_key', (array) ($queue['contexts'] ?? array()))));
        $result = array(
            'queued_user_count' => count($user_ids),
            'contexts' => $contexts,
            'full_sync_requested' => !empty($queue['full_sync']),
            'full_sync_ran' => false,
            'synced_user_ids' => array(),
            'failed_user_ids' => array(),
        );

        if ($result['full_sync_requested']) {
            $this->run_dynamic_role_sync();
            $result['full_sync_ran'] = true;
            return $result;
        }

        foreach ($user_ids as $user_id) {
            if ($this->safe_run_single_user_sync($user_id)) {
                $result['synced_user_ids'][] = $user_id;
            } else {
                $result['failed_user_ids'][] = $user_id;
            }
        }

        return $result;
    }

    protected function bridge_rest_url($route) {
        $route = '/' . ltrim((string) $route, '/');
        return add_query_arg('rest_route', $route, home_url('/'));
    }

    protected function get_user_discord_meta($user_id) {
        return array(
            'discord_user_id' => get_user_meta($user_id, 'edb_discord_user_id', true),
            'discord_username' => get_user_meta($user_id, 'edb_discord_username', true),
            'discord_global_name' => get_user_meta($user_id, 'edb_discord_global_name', true),
            'discord_avatar' => get_user_meta($user_id, 'edb_discord_avatar', true),
            'discord_linked' => get_user_meta($user_id, 'edb_discord_linked', true),
            'discord_refresh_token' => get_user_meta($user_id, 'edb_discord_refresh_token', true),
            'discord_scope' => get_user_meta($user_id, 'edb_discord_scope', true),
        );
    }

    protected function can_message() {
        if (!is_user_logged_in()) {
            return false;
        }
        $user = wp_get_current_user();
        if (user_can($user, 'manage_options')) {
            return true;
        }
        $roles = array_map('strtolower', (array) $user->roles);
        foreach (array('hr', 'it', 'manager', 'administrator', 'editor') as $role) {
            if (in_array($role, $roles, true)) {
                return true;
            }
        }
        return false;
    }

    public function shortcode_account() {
        $oauth_status = isset($_GET['edb_oauth_status']) ? sanitize_key(wp_unslash($_GET['edb_oauth_status'])) : '';
        $oauth_message = isset($_GET['edb_oauth_message']) ? sanitize_text_field(wp_unslash($_GET['edb_oauth_message'])) : '';
        if (!is_user_logged_in()) {
            $out = '<div class="edb-card">';
            if ($oauth_status === 'error') {
                $message = $oauth_message !== '' ? $oauth_message : 'Discord connection failed. Please try again.';
                $out .= '<div class="edb-callout edb-callout--error">' . esc_html($message) . '</div>';
            }
            $out .= '<p>Please log in.</p></div>';
            return $out;
        }
        $user_id = get_current_user_id();
        $discord = $this->get_user_discord_meta($user_id);
        $guilds = $this->get_cached_guilds($user_id);
        $has_guild_join_scope = $this->user_has_guild_join_scope($user_id);
        $is_discord_linked = !empty($discord['discord_linked']) && !empty($discord['discord_user_id']);
        $link_url = $this->oauth_url('link');
        $login_url = $this->oauth_url('login');
        ob_start();
        echo '<div class="edb-card"><div class="edb-card__head"><div><h3>Discord Account</h3><p>Connect Discord, review the stored guilds list, and launch a Discord-authenticated password reset.</p></div></div>';
        if ($oauth_status === 'success') {
            $message = $oauth_message !== '' ? $oauth_message : 'Discord account connected successfully.';
            echo '<div class="edb-callout"><strong>Success</strong><br>' . esc_html($message) . '</div>';
        } elseif ($oauth_status === 'error') {
            $message = $oauth_message !== '' ? $oauth_message : 'Discord connection failed. Please try again.';
            echo '<div class="edb-callout edb-callout--error">' . esc_html($message) . '</div>';
        } elseif ($oauth_status === 'needs_scope') {
            $message = $oauth_message !== '' ? $oauth_message : 'Discord connected, but server restore permission was not granted. Reconnect and approve guilds.join.';
            echo '<div class="edb-callout edb-callout--error">' . esc_html($message) . '</div>';
        }
        echo '<div class="edb-grid edb-grid--2">';
        echo '<div>';
        if ($is_discord_linked) {
            echo '<p><span class="edb-pill edb-pill--success">Connected</span></p>';
            echo '<p><strong>' . esc_html($this->discord_label_for_user($user_id)) . '</strong><br><span class="description">Discord ID ' . esc_html($discord['discord_user_id']) . '</span></p>';
            if ($has_guild_join_scope) {
                echo '<p><span class="edb-pill edb-pill--success">Server restore permission granted</span></p>';
            } else {
                echo '<div class="edb-callout edb-callout--error"><strong>Server restore permission needed</strong><br>Reconnect Discord and approve the guilds.join permission so the portal bot can add you back to the server.</div>';
            }
        } else {
            echo '<p><span class="edb-pill edb-pill--warn">Not connected yet</span></p>';
            echo '<p class="description">Connect Discord and approve guilds.join so the portal bot can restore your server membership if needed.</p>';
        }
        $link_label = !$is_discord_linked ? 'Connect Discord and allow server restore' : (!$has_guild_join_scope ? 'Grant server restore permission' : 'Reconnect Discord');
        echo '<p><a class="button button-primary" href="' . esc_url($link_url) . '">' . esc_html($link_label) . '</a> <a class="button" href="' . esc_url($login_url) . '">Log in with Discord</a></p>';
        echo '<p><a class="button" href="' . esc_url($this->password_reset_landing_url()) . '">Reset Password with Discord</a></p>';
        echo '</div>';
        echo '<div><h4>Your Guilds</h4>';
        if (empty($guilds)) {
            echo '<p class="description">No guild list cached yet. It will populate after the next authentication or refresh.</p>';
        } else {
            echo '<div class="edb-pilllist">';
            foreach ($guilds as $guild) {
                echo '<span class="edb-pill">' . esc_html($guild['name']) . '</span>';
            }
            echo '</div>';
        }
        echo '</div></div></div>';
        return ob_get_clean();
    }

    public function shortcode_message_center() {
        if (!$this->can_message()) {
            return '<div class="edb-card"><p>Not allowed.</p></div>';
        }
        return $this->render_message_center(false);
    }

    public function shortcode_password_reset() {
        return $this->render_password_reset_panel();
    }

    public function shortcode_bulk_editor_removed() {
        return '<div class="edb-card"><p>The old bulk console has been removed. Use the new Discord Message Center instead.</p></div>';
    }

    protected function render_message_center($admin_context) {
        $notice = isset($_GET['edb_notice']) ? sanitize_text_field(wp_unslash($_GET['edb_notice'])) : '';
        $data = $this->recipient_dataset();
        $known_guilds = $this->known_guild_options();
        $columns = $data['columns'];
        $default_columns_wp = array('name', 'job_title', 'division', 'job_path');
        $default_columns_discord = array('name', 'discord_account', 'discord_roles', 'guilds', 'employment_status');
        $tester_report = $this->consume_role_tester_report();

        ob_start();
        if ($notice) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notice) . '</p></div>';
        }
        echo '<div class="edb-message-center" data-edb-message-center="1" data-edb-default-columns-wp="' . esc_attr(wp_json_encode($default_columns_wp)) . '" data-edb-default-columns-discord="' . esc_attr(wp_json_encode($default_columns_discord)) . '">';
        echo '<div class="edb-view-switch" role="tablist" aria-label="Message center view">';
        echo '<button type="button" class="button button-primary" data-edb-set-view="wordpress" aria-pressed="true">WordPress</button>';
        echo '<button type="button" class="button" data-edb-set-view="discord" aria-pressed="false">Discord</button>';
        echo '</div>';

        echo '<div class="edb-message-toolbar">';
        echo '<button type="button" class="button edb-toolbar-button" data-edb-open-modal="filters">Filters</button>';
        echo '<button type="button" class="button edb-toolbar-button" data-edb-open-modal="fields">Add Fields to Table</button>';
        echo '<button type="button" class="button button-primary edb-toolbar-button" data-edb-open-modal="send">Send Message to Selected</button>';
        echo '<button type="button" class="button edb-toolbar-button" data-edb-open-modal="utilities">Selected User Utilities</button>';
        echo '<button type="button" class="button edb-toolbar-button" data-edb-open-modal="tester">Role Rule Tester</button>';
        echo '<div class="edb-selection-status"><strong data-edb-selected-count>0</strong> selected</div>';
        echo '</div>';
        echo '<div class="edb-toolbar-hint">Utilities now live in the popup tools: refresh guild lists, force join any configured guild, sync selected roles, and test why a role rule does or does not match.</div>';

        echo '<div class="edb-table-shell"><table class="widefat striped edb-directory-table" data-edb-directory-table><thead><tr>';
        echo '<th class="edb-col-check"><input type="checkbox" data-edb-select-all aria-label="Select all visible users"></th>';
        foreach ($columns as $key => $label) {
            $hidden = in_array($key, $default_columns_wp, true) ? '' : ' is-hidden';
            echo '<th class="edb-sortable' . esc_attr($hidden) . '" data-edb-col="' . esc_attr($key) . '" data-edb-sort="' . esc_attr($key) . '"><span>' . esc_html($label) . '</span><span class="edb-sort-indicator" aria-hidden="true">↕</span></th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($data['users'] as $user) {
            $row_attrs = array(
                'data-edb-row' => '1',
                'data-edb-user-id' => (string) $user['id'],
                'data-edb-discord-id' => (string) $user['discord_id'],
                'data-edb-source' => esc_attr($user['source']),
                'data-edb-search' => esc_attr(strtolower($user['search'])),
                'data-edb-wp-role' => esc_attr(strtolower(implode('|', $user['wp_roles_raw']))),
                'data-edb-group' => esc_attr(strtolower(implode('|', $user['groups']))),
                'data-edb-project' => esc_attr(strtolower(implode('|', $user['projects']))),
                'data-edb-discord-role' => esc_attr(strtolower(implode('|', $user['discord_roles']))),
                'data-edb-job-path' => esc_attr(strtolower($user['job_path'])),
                'data-edb-location' => esc_attr(strtolower($user['location'])),
                'data-edb-employment-status' => esc_attr(strtolower($user['employment_status'])),
                'data-edb-linked' => esc_attr($user['discord_id'] !== '' ? 'yes' : 'no'),
                'data-edb-name' => esc_attr(strtolower($user['label'])),
                'data-edb-job-title' => esc_attr(strtolower($user['job_title'])),
                'data-edb-division' => esc_attr(strtolower($user['division'])),
                'data-edb-guilds' => esc_attr(strtolower(implode('|', $user['guilds']))),
            );
            echo '<tr';
            foreach ($row_attrs as $attr_key => $attr_value) {
                echo ' ' . $attr_key . '="' . $attr_value . '"';
            }
            echo '>';
            echo '<td class="edb-col-check"><input type="checkbox" data-edb-row-select aria-label="Select ' . esc_attr($user['label']) . '"></td>';
            foreach ($columns as $key => $label) {
                $hidden = in_array($key, $default_columns_wp, true) ? '' : ' is-hidden';
                $value = isset($user[$key]) ? $user[$key] : '';
                $sort_value = strtolower(is_scalar($value) ? (string) $value : wp_json_encode($value));
                echo '<td class="' . esc_attr($hidden) . '" data-edb-col="' . esc_attr($key) . '" data-edb-sort-value="' . esc_attr($sort_value) . '">';
                if ($key === 'name') {
                    echo '<strong>' . esc_html($user['label']) . '</strong>';
                    if ($user['source'] === 'discord_only') {
                        echo '<div class="edb-cell-subtle">Main guild only</div>';
                    } elseif ($user['discord_id'] === '') {
                        echo '<div class="edb-cell-subtle">Portal only</div>';
                    }
                } else {
                    echo wp_kses_post($this->format_message_table_value($key, $value));
                }
                echo '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<div class="edb-table-caption">Click table headers to sort. Use the popup buttons to filter, choose visible fields, send messages, run selected-user utilities, or test role rules.</div>';
        if (!empty($tester_report)) {
            echo $this->render_role_tester_report($tester_report);
        }
        echo $this->render_message_filters_modal($data);
        echo $this->render_message_fields_modal($columns, $default_columns_wp, $default_columns_discord);
        echo $this->render_message_send_modal();
        echo $this->render_message_utilities_modal($known_guilds);
        echo $this->render_message_tester_modal($known_guilds, $data['users']);
        echo '</div>';
        return ob_get_clean();
    }

    protected function render_message_filters_modal($data) {
        ob_start();
        echo '<div class="edb-modal" data-edb-modal="filters" hidden><div class="edb-modal__backdrop" data-edb-close-modal="filters"></div><div class="edb-modal__dialog edb-modal__dialog--wide"><button type="button" class="edb-modal__close" data-edb-close-modal="filters" aria-label="Close">×</button>';
        echo '<h2>Filters</h2><p class="description">WordPress filters support path-style selectors. Discord filters focus on Has Role and Doesn&#8217;t Have Role so you can target people who are in the main guild but not in the portal.</p>';
        echo '<div class="edb-filter-scope" data-edb-filter-scope="wordpress">';
        echo '<div class="edb-modal-grid">';
        echo '<label class="edb-field"><span class="edb-field__label">Search</span><input type="search" data-edb-filter-input="search" placeholder="Search name, email, path, location, or Discord name"></label>';
        echo '<label class="edb-field"><span class="edb-field__label">WordPress Role</span><select data-edb-filter-input="wp-role"><option value="">All roles</option>';
        foreach ($data['wp_roles'] as $key => $label) {
            echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '<label class="edb-field"><span class="edb-field__label">Employment Status</span><select data-edb-filter-input="employment-status"><option value="">All statuses</option>';
        foreach ($data['employment_statuses'] as $key => $label) {
            echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '<label class="edb-field"><span class="edb-field__label">Discord Linked</span><select data-edb-filter-input="linked"><option value="">All</option><option value="yes">Linked</option><option value="no">Not linked</option></select></label>';
        echo '</div>';
        echo '<div class="edb-selector-grid">';
        echo $this->render_popup_selector('Paths', 'job-path', $data['job_paths']);
        echo $this->render_popup_selector('Locations', 'location', $data['locations']);
        echo $this->render_popup_selector('EUM Groups', 'group', $data['groups']);
        echo $this->render_popup_selector('Projects', 'project', $data['projects']);
        echo '</div></div>';
        echo '<div class="edb-filter-scope" data-edb-filter-scope="discord" hidden>';
        echo '<div class="edb-modal-grid">';
        echo '<label class="edb-field"><span class="edb-field__label">Search</span><input type="search" data-edb-filter-input="search" placeholder="Search Discord display name or ID"></label>';
        echo '<label class="edb-field"><span class="edb-field__label">Has Role</span><select data-edb-filter-input="discord-role-has"><option value="">Any role</option>';
        foreach ($data['discord_roles'] as $role) {
            echo '<option value="' . esc_attr($role) . '">' . esc_html($role) . '</option>';
        }
        echo '</select></label>';
        echo '<label class="edb-field"><span class="edb-field__label">Doesn&#8217;t Have Role</span><select data-edb-filter-input="discord-role-not"><option value="">No exclusion</option>';
        foreach ($data['discord_roles'] as $role) {
            echo '<option value="' . esc_attr($role) . '">' . esc_html($role) . '</option>';
        }
        echo '</select></label>';
        echo '<label class="edb-field"><span class="edb-field__label">Portal Link</span><select data-edb-filter-input="source"><option value="">Any</option><option value="discord_only">Main guild only</option><option value="portal">Portal users only</option><option value="both">Linked portal + Discord</option></select></label>';
        echo '</div></div>';
        echo '<div class="edb-modal-actions"><button type="button" class="button" data-edb-reset-filters="1">Reset Filters</button><button type="button" class="button button-primary" data-edb-close-modal="filters">Apply Filters</button></div>';
        echo '</div></div>';
        return ob_get_clean();
    }

    protected function render_popup_selector($label, $filter_key, $options) {
        ob_start();
        echo '<div class="edb-selector"><div class="edb-selector__title">' . esc_html($label) . '</div><div class="edb-selector__list">';
        if (empty($options)) {
            echo '<div class="edb-cell-subtle">No options available yet.</div>';
        } else {
            foreach ($options as $option) {
                echo '<label class="edb-selector__item"><input type="checkbox" data-edb-filter-checkbox="' . esc_attr($filter_key) . '" value="' . esc_attr(strtolower($option)) . '"> <span>' . esc_html($option) . '</span></label>';
            }
        }
        echo '</div></div>';
        return ob_get_clean();
    }

    protected function render_message_fields_modal($columns, $default_columns_wp, $default_columns_discord) {
        ob_start();
        echo '<div class="edb-modal" data-edb-modal="fields" hidden><div class="edb-modal__backdrop" data-edb-close-modal="fields"></div><div class="edb-modal__dialog"><button type="button" class="edb-modal__close" data-edb-close-modal="fields" aria-label="Close">×</button>';
        echo '<h2>Add Fields to Table</h2><p class="description">Choose which columns show in the directory. WordPress and Discord views keep their own saved field sets.</p>';
        echo '<div class="edb-field-presets"><button type="button" class="button" data-edb-apply-column-preset="wordpress">WordPress Defaults</button><button type="button" class="button" data-edb-apply-column-preset="discord">Discord Defaults</button></div>';
        echo '<div class="edb-selector__list edb-selector__list--fields">';
        foreach ($columns as $key => $label) {
            $checked = in_array($key, $default_columns_wp, true) ? ' checked' : '';
            echo '<label class="edb-selector__item"><input type="checkbox" data-edb-column-toggle="' . esc_attr($key) . '"' . $checked . '> <span>' . esc_html($label) . '</span></label>';
        }
        echo '</div>';
        echo '<div class="edb-modal-actions"><button type="button" class="button button-primary" data-edb-close-modal="fields">Done</button></div>';
        echo '</div></div>';
        return ob_get_clean();
    }

    protected function render_message_send_modal() {
        ob_start();
        echo '<div class="edb-modal" data-edb-modal="send" hidden><div class="edb-modal__backdrop" data-edb-close-modal="send"></div><div class="edb-modal__dialog edb-modal__dialog--wide"><button type="button" class="edb-modal__close" data-edb-close-modal="send" aria-label="Close">×</button>';
        echo '<h2>Send Message to Selected</h2><p class="description" data-edb-selected-summary>Choose one or more people in the table first. Discord-only rows can receive Discord messages even when they are not in the portal.</p>';
        echo '<form method="post" data-edb-selection-form="send">';
        wp_nonce_field(self::NONCE_ACTION, 'edb_nonce');
        echo '<input type="hidden" name="edb_action" value="send_message_ui">';
        echo '<input type="hidden" name="edb_selected_user_ids" value="">';
        echo '<input type="hidden" name="edb_selected_discord_ids" value="">';
        echo '<div class="edb-modal-grid">';
        echo '<label class="edb-field"><span class="edb-field__label">Send Through</span><select name="edb_delivery"><option value="discord">Discord DM</option><option value="wordpress">WordPress Email</option><option value="both">Both</option></select></label>';
        echo '<label class="edb-field"><span class="edb-field__label">Email Subject</span><input type="text" name="edb_email_subject" placeholder="Used for WordPress email sends"></label>';
        echo '</div>';
        echo '<label class="edb-field"><span class="edb-field__label">Message</span><textarea class="large-text" rows="10" name="edb_message" placeholder="Use placeholders like {{user_name}}, {{user_email}}, {{discord_name}}, or {{actor_user_name}}"></textarea></label>';
        echo '<div class="edb-modal-actions"><button type="submit" class="button button-primary">Send to Selected</button></div>';
        echo '</form></div></div>';
        return ob_get_clean();
    }

    protected function render_message_utilities_modal($known_guilds) {
        ob_start();
        echo '<div class="edb-modal" data-edb-modal="utilities" hidden><div class="edb-modal__backdrop" data-edb-close-modal="utilities"></div><div class="edb-modal__dialog"><button type="button" class="edb-modal__close" data-edb-close-modal="utilities" aria-label="Close">×</button>';
        echo '<h2>Selected User Utilities</h2><p class="description">Utilities apply to selected portal users. Guild-only Discord members are ignored for force join and role sync because those actions need a linked WordPress user with a stored Discord token.</p>';
        echo '<form method="post" data-edb-selection-form="utilities">';
        wp_nonce_field(self::NONCE_ACTION, 'edb_nonce');
        echo '<input type="hidden" name="edb_action" value="member_utility_ui">';
        echo '<input type="hidden" name="edb_selected_user_ids" value="">';
        echo '<p class="description" data-edb-selected-summary>Choose one or more people in the table first.</p>';
        $guild_select = '<select name="edb_target_guild_id"><option value="">Primary guild</option>';
        foreach ($known_guilds as $guild_id => $guild_label) {
            $guild_select .= '<option value="' . esc_attr($guild_id) . '">' . esc_html($guild_label) . '</option>';
        }
        $guild_select .= '</select>';
        echo '<div class="edb-modal-grid">';
        $this->settings_field('Target Guild', $guild_select);
        $this->settings_field('Custom Guild ID', '<input class="regular-text" name="edb_target_guild_id_custom" placeholder="Optional override">');
        echo '</div>';
        echo '<div class="edb-modal-actions"><button type="submit" class="button" name="edb_utility" value="refresh_guilds">Refresh Guild Lists</button><button type="submit" class="button" name="edb_utility" value="force_join_guild">Force Join Selected Guild</button><button type="submit" class="button button-primary" name="edb_utility" value="sync_roles">Sync Roles</button></div>';
        echo '</form></div></div>';
        return ob_get_clean();
    }

    protected function render_message_tester_modal($known_guilds, $users) {
        ob_start();
        echo '<div class="edb-modal" data-edb-modal="tester" hidden><div class="edb-modal__backdrop" data-edb-close-modal="tester"></div><div class="edb-modal__dialog edb-modal__dialog--wide"><button type="button" class="edb-modal__close" data-edb-close-modal="tester" aria-label="Close">×</button>';
        echo '<h2>Role Rule Tester</h2><p class="description">Pick a linked portal user and optionally a target guild to see the exact employment status, guild membership checks, matched rules, and failure reasons.</p>';
        echo '<form method="post" data-edb-selection-form="tester">';
        wp_nonce_field(self::NONCE_ACTION, 'edb_nonce');
        echo '<input type="hidden" name="edb_action" value="role_rule_tester_ui">';
        echo '<input type="hidden" name="edb_selected_user_ids" value="">';
        echo '<p class="description" data-edb-selected-summary>Choose one portal user in the table first, or pick one below.</p>';
        $user_options = '<select name="edb_test_user_id"><option value="">Use first selected portal user</option>';
        foreach ($users as $user) {
            if (empty($user['id'])) {
                continue;
            }
            $user_options .= '<option value="' . (int) $user['id'] . '">' . esc_html($user['label'] . ' — ' . $user['email']) . '</option>';
        }
        $user_options .= '</select>';
        $guild_select = '<select name="edb_target_guild_id"><option value="">Auto / primary guild</option>';
        foreach ($known_guilds as $guild_id => $guild_label) {
            $guild_select .= '<option value="' . esc_attr($guild_id) . '">' . esc_html($guild_label) . '</option>';
        }
        $guild_select .= '</select>';
        echo '<div class="edb-modal-grid">';
        $this->settings_field('Portal User', $user_options);
        $this->settings_field('Target Guild', $guild_select);
        $this->settings_field('Custom Guild ID', '<input class="regular-text" name="edb_target_guild_id_custom" placeholder="Optional override">');
        echo '</div>';
        echo '<div class="edb-callout"><strong>Status keys the tester expects:</strong> <code>employment_status: active</code> and <code>employment_status: terminated</code>. The report below will also show which user meta actually drove the status.</div>';
        echo '<div class="edb-modal-actions"><button type="submit" class="button button-primary">Run Role Rule Test</button></div>';
        echo '</form></div></div>';
        return ob_get_clean();
    }

    protected function role_tester_report_key($viewer_user_id) {
        return 'edb_role_rule_test_' . (int) $viewer_user_id;
    }

    protected function store_role_tester_report($report) {
        $viewer_user_id = get_current_user_id();
        if ($viewer_user_id > 0) {
            set_transient($this->role_tester_report_key($viewer_user_id), $report, 600);
        }
    }

    protected function consume_role_tester_report() {
        $viewer_user_id = get_current_user_id();
        if ($viewer_user_id <= 0) {
            return array();
        }
        $key = $this->role_tester_report_key($viewer_user_id);
        $report = get_transient($key);
        delete_transient($key);
        return is_array($report) ? $report : array();
    }

    protected function render_role_tester_report($report) {
        if (empty($report)) {
            return '';
        }
        if (!empty($report['error'])) {
            return '<div class="edb-card edb-card--wide edb-card--tester"><h2>Role Rule Tester</h2><div class="edb-callout edb-callout--error">' . esc_html($report['error']) . '</div></div>';
        }
        ob_start();
        echo '<div class="edb-card edb-card--wide edb-card--tester">';
        echo '<div class="edb-card__head"><div><h2>Role Rule Tester</h2><p class="description">Most recent test for ' . esc_html($report['user']['name']) . '.</p></div><div class="edb-pilllist"><span class="edb-pill">Computed status: ' . esc_html($report['employment_status']) . '</span><span class="edb-pill edb-pill--soft">Discord linked: ' . esc_html($report['discord']['linked'] ? 'Yes' : 'No') . '</span><span class="edb-pill edb-pill--soft">Target guild: ' . esc_html($report['target_guild_id'] ?: 'Primary') . '</span></div></div>';
        echo '<div class="edb-tester-grid">';
        echo '<div class="edb-callout"><strong>User</strong><br>' . esc_html($report['user']['name']) . ' &lt;' . esc_html($report['user']['email']) . '&gt;<br><span class="description">User ID ' . (int) $report['user']['id'] . '</span></div>';
        echo '<div class="edb-callout"><strong>Status Resolution</strong><br>Final: <strong>' . esc_html($report['employment_status']) . '</strong><br><span class="description">eum_terminated=' . esc_html($report['meta']['eum_terminated']) . ' · system:eum_terminated=' . esc_html($report['meta']['system_eum_terminated']) . ' · status=' . esc_html($report['meta']['status']) . '</span></div>';
        echo '<div class="edb-callout"><strong>Guild Membership</strong><br>Cached in target guild: <strong>' . esc_html($report['discord']['cached_in_target'] ? 'Yes' : 'No') . '</strong><br>Bot can see member in target guild: <strong>' . esc_html($report['discord']['member_in_target'] ? 'Yes' : 'No') . '</strong></div>';
        echo '<div class="edb-callout"><strong>Live Conditions</strong><br>Clocked in now: <strong>' . esc_html($report['conditions']['currently_clocked_in'] ? 'Yes' : 'No') . '</strong><br>Scheduled now: <strong>' . esc_html($report['conditions']['scheduled_now'] ? 'Yes' : 'No') . '</strong></div>';
        echo '</div>';
        if (!empty($report['primary']['auto_roles'])) {
            echo '<h3>Primary Guild Desired Roles</h3><div class="edb-pilllist">';
            foreach ($report['primary']['auto_roles'] as $role_name) {
                echo '<span class="edb-pill">' . esc_html($role_name) . '</span>';
            }
            echo '</div>';
        }
        if (!empty($report['primary']['term_ids'])) {
            echo '<h3>Portal Term IDs Used For Auto Roles</h3><div class="edb-pilllist">';
            foreach ($report['primary']['term_ids'] as $label => $ids) {
                echo '<span class="edb-pill edb-pill--soft">' . esc_html($label . ': ' . (empty($ids) ? 'none' : implode(', ', array_map('strval', $ids)))) . '</span>';
            }
            echo '</div>';
        }
        echo '<h3>Easy Primary Guild Role Maps</h3>';
        echo $this->render_role_map_tester_rows(isset($report['primary']['easy_maps']) ? $report['primary']['easy_maps'] : array());
        echo '<h3>Primary Guild JSON Rules</h3>';
        echo $this->render_role_tester_rule_rows($report['primary']['rules']);
        echo '<h3>Secondary Guild Rules</h3>';
        if (empty($report['secondary'])) {
            echo '<p class="description">No secondary-guild rules matched the selected guild scope.</p>';
        } else {
            foreach ($report['secondary'] as $guild_report) {
                echo '<div class="edb-tester-guild-block"><h4>' . esc_html($guild_report['guild_label']) . '</h4>';
                if (!empty($guild_report['matched_role_names'])) {
                    echo '<div class="edb-pilllist">';
                    foreach ($guild_report['matched_role_names'] as $role_name) {
                        echo '<span class="edb-pill edb-pill--soft">' . esc_html($role_name) . '</span>';
                    }
                    echo '</div>';
                }
                echo $this->render_role_tester_rule_rows($guild_report['rules']);
                echo '</div>';
            }
        }
        echo '</div>';
        return ob_get_clean();
    }

    protected function render_role_tester_rule_rows($rule_rows) {
        if (empty($rule_rows)) {
            return '<p class="description">No rules in this scope.</p>';
        }
        ob_start();
        echo '<div class="edb-tester-rule-list">';
        foreach ($rule_rows as $row) {
            echo '<div class="edb-tester-rule ' . ($row['matched'] ? 'is-match' : 'is-miss') . '">';
            echo '<div class="edb-tester-rule__head"><strong>Rule #' . (int) $row['index'] . '</strong><span class="edb-pill ' . ($row['matched'] ? 'edb-pill--success' : 'edb-pill--warn') . '">' . esc_html($row['matched'] ? 'Matched' : 'Did not match') . '</span></div>';
            echo '<div class="edb-tester-rule__meta">Role ID: <code>' . esc_html($row['role_id']) . '</code>';
            if ($row['role_name'] !== '') {
                echo ' · ' . esc_html($row['role_name']);
            }
            echo '</div>';
            if (!$row['matched'] && !empty($row['reasons'])) {
                echo '<ul class="edb-tester-reasons">';
                foreach ($row['reasons'] as $reason) {
                    echo '<li>' . esc_html($reason) . '</li>';
                }
                echo '</ul>';
            }
            echo '<pre>' . esc_html(wp_json_encode($row['rule'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
            echo '</div>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    protected function render_role_map_tester_rows($rows) {
        if (empty($rows)) {
            return '<p class="description">No easy role maps configured.</p>';
        }
        ob_start();
        echo '<div class="edb-tester-rule-list">';
        foreach ($rows as $row) {
            echo '<div class="edb-tester-rule ' . ($row['matched'] ? 'is-match' : 'is-miss') . '">';
            echo '<div class="edb-tester-rule__head"><strong>Map #' . (int) $row['index'] . '</strong><span class="edb-pill ' . ($row['matched'] ? 'edb-pill--success' : 'edb-pill--warn') . '">' . esc_html($row['matched'] ? 'Matched' : 'Did not match') . '</span></div>';
            echo '<div class="edb-tester-rule__meta">' . esc_html(ucfirst(str_replace('_', ' ', $row['category']))) . ': <code>' . esc_html($row['key']) . '</code> Â· Discord role: <code>' . esc_html($row['role_id']) . '</code>';
            if ($row['role_name'] !== '') {
                echo ' Â· ' . esc_html($row['role_name']);
            }
            echo '</div>';
            echo '<pre>' . esc_html($row['source']) . '</pre>';
            echo '</div>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    protected function format_message_table_value($key, $value) {
        if (is_array($value)) {
            if (empty($value)) {
                return '<span class="edb-cell-subtle">—</span>';
            }
            $bits = array();
            foreach ($value as $item) {
                $bits[] = '<span class="edb-pill edb-pill--soft">' . esc_html((string) $item) . '</span>';
            }
            return implode(' ', $bits);
        }
        $value = trim((string) $value);
        if ($value === '') {
            return '<span class="edb-cell-subtle">—</span>';
        }
        if ($key === 'discord_account' || $key === 'email' || $key === 'manager' || $key === 'division' || $key === 'job_title' || $key === 'job_path' || $key === 'location' || $key === 'employment_status') {
            return '<span>' . esc_html($value) . '</span>';
        }
        return nl2br(esc_html($value));
    }

    protected function render_filter_bar($wp_roles, $groups, $discord_roles, $employment_statuses, $context) {
        ob_start();
        echo '<div class="edb-filterbar" data-edb-filterbar="' . esc_attr($context) . '">';
        echo '<label><span>Search</span><input type="search" data-edb-filter="search" placeholder="Search people"></label>';
        echo '<label><span>WordPress Role</span><select data-edb-filter="wp-role"><option value="">All roles</option>';
        foreach ($wp_roles as $key => $label) {
            echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '<label><span>EUM Group</span><select data-edb-filter="eum-group"><option value="">All groups</option>';
        foreach ($groups as $group) {
            echo '<option value="' . esc_attr($group) . '">' . esc_html($group) . '</option>';
        }
        echo '</select></label>';
        echo '<label><span>Discord Role</span><select data-edb-filter="discord-role"><option value="">All Discord roles</option>';
        foreach ($discord_roles as $role) {
            echo '<option value="' . esc_attr($role) . '">' . esc_html($role) . '</option>';
        }
        echo '</select></label>';
        echo '<label><span>Employment Status</span><select data-edb-filter="employment-status"><option value="">All statuses</option>';
        foreach ($employment_statuses as $status_key => $status_label) {
            echo '<option value="' . esc_attr($status_key) . '">' . esc_html($status_label) . '</option>';
        }
        echo '</select></label>';
        echo '</div>';
        return ob_get_clean();
    }

    protected function render_user_multiselect($users, $name, $label, $summary, $context) {
        ob_start();
        echo '<div class="edb-multi" data-edb-multi="1" data-edb-context="' . esc_attr($context) . '">';
        echo '<details open="open"><summary><strong>' . esc_html($label) . '</strong><span class="edb-summary" data-edb-summary="' . esc_attr($summary) . '">' . esc_html($summary) . '</span></summary>';
        echo '<div class="edb-multi__body"><div class="edb-multi__actions"><button type="button" class="button button-small" data-edb-select="visible">Select visible</button> <button type="button" class="button button-small" data-edb-select="none">Clear</button></div><div class="edb-multi__list">';
        foreach ($users as $user) {
            $attrs = sprintf(
                'data-edb-search="%s" data-edb-wp-role="%s" data-edb-eum-group="%s" data-edb-discord-role="%s" data-edb-employment-status="%s"',
                esc_attr(strtolower($user['search'])),
                esc_attr(strtolower(implode(' ', $user['wp_roles']))),
                esc_attr(strtolower(implode(' ', $user['groups']))),
                esc_attr(strtolower(implode(' ', $user['discord_roles']))),
                esc_attr(strtolower($user['employment_status']))
            );
            echo '<label class="edb-option" ' . $attrs . '><input type="checkbox" name="' . esc_attr($name) . '[]" value="' . (int) $user['id'] . '"><span><strong>' . esc_html($user['label']) . '</strong><small>' . esc_html($user['meta']) . '</small></span></label>';
        }
        echo '</div></div></details></div>';
        return ob_get_clean();
    }

    protected function render_discord_multiselect($users, $name, $label, $summary, $context) {
        ob_start();
        echo '<div class="edb-multi" data-edb-multi="1" data-edb-context="' . esc_attr($context) . '">';
        echo '<details><summary><strong>' . esc_html($label) . '</strong><span class="edb-summary" data-edb-summary="' . esc_attr($summary) . '">' . esc_html($summary) . '</span></summary>';
        echo '<div class="edb-multi__body"><div class="edb-multi__actions"><button type="button" class="button button-small" data-edb-select="visible">Select visible</button> <button type="button" class="button button-small" data-edb-select="none">Clear</button></div><div class="edb-multi__list">';
        foreach ($users as $user) {
            if (empty($user['discord_id'])) {
                continue;
            }
            $attrs = sprintf(
                'data-edb-search="%s" data-edb-wp-role="%s" data-edb-eum-group="%s" data-edb-discord-role="%s" data-edb-employment-status="%s"',
                esc_attr(strtolower($user['search'])),
                esc_attr(strtolower(implode(' ', $user['wp_roles']))),
                esc_attr(strtolower(implode(' ', $user['groups']))),
                esc_attr(strtolower(implode(' ', $user['discord_roles']))),
                esc_attr(strtolower($user['employment_status']))
            );
            echo '<label class="edb-option" ' . $attrs . '><input type="checkbox" name="' . esc_attr($name) . '[]" value="' . esc_attr($user['discord_id']) . '"><span><strong>' . esc_html($user['discord_label']) . '</strong><small>' . esc_html($user['label']) . '</small></span></label>';
        }
        echo '</div></div></details></div>';
        return ob_get_clean();
    }

    protected function recipient_dataset() {
        $users = array();
        $linked_by_discord = array();
        $wp_roles = array();
        $groups = array();
        $projects = array();
        $discord_roles = array();
        $employment_statuses = array();
        $job_paths = array();
        $locations = array();

        foreach (get_users(array('orderby' => 'display_name', 'order' => 'ASC')) as $user) {
            $user_groups = $this->user_term_names($user->ID, 'eum_user_group');
            $user_projects = $this->user_term_names($user->ID, 'eum_project');
            $primary_roles = (array) get_user_meta($user->ID, self::PRIMARY_ROLE_NAMES_META, true);
            $discord_id = (string) get_user_meta($user->ID, 'edb_discord_user_id', true);
            $employment_status = $this->user_employment_status($user->ID);
            $job_path = $this->user_job_path_label($user->ID);
            $location = $this->user_tax_path_label($user->ID, 'eum_location');
            $job_title = $this->user_job_title_label($user->ID);
            $division = $this->user_division_label($user->ID);
            $manager = $this->user_manager_label($user->ID);
            $guilds = wp_list_pluck($this->get_cached_guilds($user->ID), 'name');

            foreach ((array) $user->roles as $role) {
                if (!isset($wp_roles[$role])) {
                    $wp_roles[$role] = translate_user_role(ucwords(str_replace('_', ' ', $role)));
                }
            }
            foreach ($user_groups as $group) {
                $groups[$group] = $group;
            }
            foreach ($user_projects as $project) {
                $projects[$project] = $project;
            }
            foreach ($primary_roles as $role_name) {
                if ($role_name !== '') {
                    $discord_roles[$role_name] = $role_name;
                }
            }
            if ($job_path !== '') {
                $job_paths[$job_path] = $job_path;
            }
            if ($location !== '') {
                $locations[$location] = $location;
            }
            $employment_statuses[$employment_status] = ucfirst($employment_status);

            $row = array(
                'id' => (int) $user->ID,
                'discord_id' => $discord_id,
                'source' => $discord_id !== '' ? 'both' : 'portal',
                'label' => $user->display_name,
                'name' => $user->display_name,
                'email' => $user->user_email,
                'discord_account' => $this->discord_label_for_user($user->ID),
                'job_title' => $job_title,
                'division' => $division,
                'job_path' => $job_path,
                'location' => $location,
                'manager' => $manager,
                'wp_roles' => array_values(array_filter(array_map(array($this, 'format_wp_role_label'), (array) $user->roles))),
                'wp_roles_raw' => array_map('strtolower', (array) $user->roles),
                'groups' => array_values($user_groups),
                'projects' => array_values($user_projects),
                'discord_roles' => array_values(array_filter($primary_roles)),
                'guilds' => array_values(array_filter($guilds)),
                'employment_status' => ucfirst($employment_status),
                'search' => strtolower(trim($user->display_name . ' ' . $user->user_email . ' ' . $this->discord_label_for_user($user->ID) . ' ' . $job_path . ' ' . $location . ' ' . implode(' ', $user_groups) . ' ' . implode(' ', $user_projects) . ' ' . implode(' ', $primary_roles) . ' ' . $employment_status . ' ' . $manager)),
            );
            $users[] = $row;
            if ($discord_id !== '') {
                $linked_by_discord[$discord_id] = count($users) - 1;
            }
        }

        foreach ($this->get_main_guild_member_rows() as $member) {
            foreach ($member['discord_roles'] as $role_name) {
                $discord_roles[$role_name] = $role_name;
            }
            if (!empty($member['discord_id']) && isset($linked_by_discord[$member['discord_id']])) {
                $idx = $linked_by_discord[$member['discord_id']];
                $users[$idx]['discord_roles'] = array_values(array_unique(array_filter(array_merge($users[$idx]['discord_roles'], $member['discord_roles']))));
                $users[$idx]['guilds'] = array_values(array_unique(array_filter(array_merge($users[$idx]['guilds'], $member['guilds']))));
                $users[$idx]['source'] = 'both';
                $users[$idx]['discord_account'] = $member['discord_account'];
                $users[$idx]['search'] .= ' ' . strtolower($member['discord_account'] . ' ' . implode(' ', $member['discord_roles']));
            } else {
                $users[] = $member;
            }
        }

        usort($users, function ($a, $b) {
            return strcasecmp((string) $a['label'], (string) $b['label']);
        });
        asort($wp_roles);
        ksort($groups);
        ksort($projects);
        ksort($discord_roles);
        ksort($job_paths);
        ksort($locations);
        ksort($employment_statuses);

        return array(
            'users' => $users,
            'wp_roles' => $wp_roles,
            'groups' => array_values($groups),
            'projects' => array_values($projects),
            'discord_roles' => array_values($discord_roles),
            'job_paths' => array_values($job_paths),
            'locations' => array_values($locations),
            'employment_statuses' => $employment_statuses,
            'columns' => array(
                'name' => 'Name',
                'job_title' => 'Job Title',
                'division' => 'Division',
                'job_path' => 'Job Path',
                'location' => 'Location',
                'manager' => 'Manager',
                'email' => 'Email',
                'wp_roles' => 'WordPress Roles',
                'groups' => 'EUM Groups',
                'projects' => 'Projects',
                'discord_account' => 'Discord Account',
                'discord_roles' => 'Discord Roles',
                'guilds' => 'Guilds',
                'employment_status' => 'Status',
            ),
        );
    }

    protected function get_main_guild_member_rows() {
        $settings = $this->settings();
        $guild_id = isset($settings['guild_id']) ? (string) $settings['guild_id'] : '';
        if ($guild_id === '') {
            return array();
        }

        $cache_key = 'edb_main_guild_members_' . md5($guild_id);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $role_map = array();
        foreach ($this->get_guild_roles($guild_id) as $role) {
            if (!empty($role['id']) && !empty($role['name']) && $role['name'] !== '@everyone') {
                $role_map[(string) $role['id']] = (string) $role['name'];
            }
        }

        $rows = array();
        $after = '0';
        $loops = 0;
        do {
            $resp = $this->discord_api('GET', '/guilds/' . $guild_id . '/members?limit=1000&after=' . rawurlencode($after), null, true);
            if ((int) $resp['code'] !== 200 || !is_array($resp['body'])) {
                set_transient($cache_key, array(), 300);
                return array();
            }
            $members = $resp['body'];
            foreach ($members as $member) {
                $user = isset($member['user']) && is_array($member['user']) ? $member['user'] : array();
                $discord_id = isset($user['id']) ? (string) $user['id'] : '';
                if ($discord_id === '' || !empty($user['bot'])) {
                    continue;
                }
                $role_names = array();
                foreach ((array) ($member['roles'] ?? array()) as $role_id) {
                    $role_id = (string) $role_id;
                    if (isset($role_map[$role_id])) {
                        $role_names[] = $role_map[$role_id];
                    }
                }
                $global_name = isset($user['global_name']) ? (string) $user['global_name'] : '';
                $username = isset($user['username']) ? (string) $user['username'] : '';
                $display = $global_name !== '' ? $global_name : $username;
                $rows[] = array(
                    'id' => 0,
                    'discord_id' => $discord_id,
                    'source' => 'discord_only',
                    'label' => $display,
                    'name' => $display,
                    'email' => '',
                    'discord_account' => $display . ($username && $username !== $display ? ' (@' . $username . ')' : ''),
                    'job_title' => '',
                    'division' => '',
                    'job_path' => '',
                    'location' => '',
                    'manager' => '',
                    'wp_roles' => array(),
                    'wp_roles_raw' => array(),
                    'groups' => array(),
                    'projects' => array(),
                    'discord_roles' => array_values(array_unique($role_names)),
                    'guilds' => array('Primary Guild'),
                    'employment_status' => 'Discord Only',
                    'search' => strtolower(trim($display . ' ' . $username . ' ' . $discord_id . ' ' . implode(' ', $role_names))),
                );
                $after = $discord_id;
            }
            $loops++;
        } while (count($members) === 1000 && $loops < 5);

        set_transient($cache_key, $rows, 300);
        return $rows;
    }

    protected function format_wp_role_label($role) {
        $role = (string) $role;
        if ($role === '') {
            return '';
        }
        return translate_user_role(ucwords(str_replace('_', ' ', $role)));
    }

    protected function user_tax_path_label($user_id, $taxonomy, $separator = ' / ') {
        if (!$user_id || !taxonomy_exists($taxonomy)) {
            return '';
        }
        $terms = wp_get_object_terms($user_id, $taxonomy, array('fields' => 'all'));
        if (!is_array($terms) || empty($terms)) {
            return sanitize_text_field((string) get_user_meta($user_id, $taxonomy, true));
        }
        $term = $terms[0];
        if (!is_object($term)) {
            return '';
        }
        $names = array(sanitize_text_field((string) $term->name));
        $parent = isset($term->parent) ? (int) $term->parent : 0;
        while ($parent > 0) {
            $parent_term = get_term($parent, $taxonomy);
            if (!$parent_term || is_wp_error($parent_term)) {
                break;
            }
            array_unshift($names, sanitize_text_field((string) $parent_term->name));
            $parent = (int) $parent_term->parent;
        }
        return implode($separator, array_filter($names));
    }

    protected function user_division_label($user_id) {
        $segments = $this->user_job_path_segments($user_id);
        return !empty($segments) ? (string) $segments[0] : '';
    }

    protected function user_manager_label($user_id) {
        $manager_id = (int) get_user_meta($user_id, 'eum_manager_user_id', true);
        if ($manager_id <= 0) {
            $manager_id = (int) get_user_meta($user_id, 'manager_user_id', true);
        }
        if ($manager_id <= 0) {
            return '';
        }
        $manager = get_userdata($manager_id);
        return $manager ? $manager->display_name : '';
    }

    public function handle_oauth_requests() {
        if (empty($_GET['edb_discord_oauth'])) {
            return;
        }
        $action = sanitize_key(wp_unslash($_GET['edb_discord_oauth']));
        if ($action === 'start') {
            $this->start_oauth_request();
        }
        if ($action !== 'callback') {
            return;
        }
        $settings = $this->settings();
        $raw_state = isset($_GET['state']) ? wp_unslash($_GET['state']) : '';
        $state = sanitize_text_field(rawurldecode((string) $raw_state));
        $oauth_state = $this->consume_oauth_state($state);
        if (empty($oauth_state)) {
            $this->redirect_oauth_status('error', 'Discord connection could not be verified. Please start the connection again.');
        }
        $mode = $oauth_state['mode'];
        $user_id = (int) $oauth_state['user_id'];
        $return_url = !empty($oauth_state['return_url']) ? $oauth_state['return_url'] : $this->oauth_return_url($mode, $user_id);
        if (empty($_GET['code'])) {
            if ($mode === 'link') {
                $this->redirect_oauth_status('error', 'Discord did not return an authorization code.', $return_url);
            }
            wp_die('Missing code.');
        }
        $token = wp_remote_post('https://discord.com/api/oauth2/token', array(
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
            'body' => array(
                'client_id' => $settings['client_id'],
                'client_secret' => $settings['client_secret'],
                'grant_type' => 'authorization_code',
                'code' => sanitize_text_field(wp_unslash($_GET['code'])),
                'redirect_uri' => $settings['redirect_uri'],
            ),
            'timeout' => 20,
        ));
        $data = json_decode(wp_remote_retrieve_body($token), true);
        if (empty($data['access_token'])) {
            if ($mode === 'link') {
                $this->redirect_oauth_status('error', 'Discord token exchange failed. Please try again.', $return_url);
            }
            wp_die('Discord token exchange failed: ' . esc_html(wp_remote_retrieve_body($token)));
        }
        $me = $this->discord_get('/users/@me', $data['access_token'], false);
        if (empty($me['id'])) {
            if ($mode === 'link') {
                $this->redirect_oauth_status('error', 'Discord authentication completed, but the Discord account details could not be loaded.', $return_url);
            }
            wp_die('Unable to fetch Discord user.');
        }

        if ($mode === 'login') {
            $target_id = $this->find_user_by_discord_or_email($me);
            if (!$target_id) {
                wp_die('No connected WordPress account was found for this Discord user.');
            }
            $this->save_discord_identity($target_id, $me, $data);
            if (!empty($settings['allow_login'])) {
                wp_set_auth_cookie($target_id, true);
            }
            wp_safe_redirect(home_url('/'));
            exit;
        }

        if ($mode === 'reset') {
            $target_id = $this->find_user_by_discord_id_only($me['id']);
            if (!$target_id) {
                wp_safe_redirect(add_query_arg('edb_reset_error', 'not-linked', $this->password_reset_landing_url()));
                exit;
            }
            $this->save_discord_identity($target_id, $me, $data);
            $user = get_userdata($target_id);
            if (!$user) {
                wp_safe_redirect(add_query_arg('edb_reset_error', 'user-missing', $this->password_reset_landing_url()));
                exit;
            }
            $key = get_password_reset_key($user);
            $reset_url = network_site_url('wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode($user->user_login), 'login');
            wp_safe_redirect($reset_url);
            exit;
        }

        if (!is_user_logged_in() || (int) $user_id !== get_current_user_id()) {
            $this->redirect_oauth_status('error', 'You must still be logged in to finish connecting Discord.', $return_url);
        }
        $this->save_discord_identity((int) $user_id, $me, $data);
        if (!$this->user_has_guild_join_scope((int) $user_id)) {
            $this->redirect_oauth_status('needs_scope', 'Discord connected, but guilds.join was not granted. Please reconnect and approve the server restore permission.', $return_url);
        }
        $this->redirect_oauth_status('success', 'Discord account connected with server restore permission. Discord sync queued.', $return_url);
    }

    protected function start_oauth_request() {
        $mode = isset($_GET['mode']) ? sanitize_key(wp_unslash($_GET['mode'])) : '';
        $user_id = isset($_GET['user_id']) ? (int) wp_unslash($_GET['user_id']) : 0;
        if (!in_array($mode, array('link', 'login', 'reset'), true)) {
            wp_die('Invalid OAuth mode.');
        }
        if ($mode === 'link') {
            if (!is_user_logged_in()) {
                nocache_headers();
                wp_safe_redirect(wp_login_url($this->oauth_url('link')));
                exit;
            }
            $user_id = get_current_user_id();
        } else {
            $user_id = 0;
        }
        nocache_headers();
        // Discord OAuth must redirect off-site, so this cannot use wp_safe_redirect().
        wp_redirect($this->build_discord_oauth_url($mode, $user_id));
        exit;
    }

    protected function oauth_state_transient_key($token) {
        return 'edb_oauth_state_' . md5((string) $token);
    }

    protected function create_oauth_state($mode, $user_id) {
        $mode = sanitize_key($mode);
        $user_id = (int) $user_id;
        $token = wp_generate_password(32, false, false);
        set_transient($this->oauth_state_transient_key($token), array(
            'mode' => $mode,
            'user_id' => $user_id,
            'return_url' => $this->oauth_return_url($mode, $user_id),
        ), HOUR_IN_SECONDS);
        return $token;
    }

    protected function consume_oauth_state($state) {
        $state = trim((string) $state);
        if ($state === '') {
            return false;
        }

        $candidates = array(
            array(
                'token' => $state,
                'mode' => '',
                'user_id' => 0,
            ),
        );

        if (strpos($state, '|') !== false) {
            $parts = explode('|', $state, 3);
            if (count($parts) === 3) {
                $candidates[] = array(
                    'token' => sanitize_text_field($parts[0]),
                    'mode' => sanitize_key($parts[1]),
                    'user_id' => (int) $parts[2],
                );
            }
        }

        if (preg_match('/^([A-Za-z0-9]{32})(link|login|reset)(\d+)$/', $state, $matches)) {
            $candidates[] = array(
                'token' => $matches[1],
                'mode' => $matches[2],
                'user_id' => (int) $matches[3],
            );
        }

        foreach ($candidates as $candidate) {
            $token = preg_replace('/[^A-Za-z0-9]/', '', (string) $candidate['token']);
            if ($token === '') {
                continue;
            }

            $stored = get_transient($this->oauth_state_transient_key($token));
            if (is_array($stored)) {
                delete_transient($this->oauth_state_transient_key($token));
                $mode = sanitize_key(isset($stored['mode']) ? $stored['mode'] : '');
                $user_id = (int) (isset($stored['user_id']) ? $stored['user_id'] : 0);
                if (!in_array($mode, array('link', 'login', 'reset'), true)) {
                    continue;
                }
                if (!empty($candidate['mode']) && $candidate['mode'] !== $mode) {
                    continue;
                }
                if (!empty($candidate['user_id']) && (int) $candidate['user_id'] !== $user_id) {
                    continue;
                }
                return array(
                    'mode' => $mode,
                    'user_id' => $user_id,
                    'return_url' => !empty($stored['return_url']) ? esc_url_raw($stored['return_url']) : $this->oauth_return_url($mode, $user_id),
                );
            }

            $mode = sanitize_key(isset($candidate['mode']) ? $candidate['mode'] : '');
            $user_id = (int) (isset($candidate['user_id']) ? $candidate['user_id'] : 0);
            if ($mode && $user_id >= 0 && wp_verify_nonce($token, 'edb_oauth_' . $mode . '_' . $user_id)) {
                return array(
                    'mode' => $mode,
                    'user_id' => $user_id,
                    'return_url' => $this->oauth_return_url($mode, $user_id),
                );
            }
        }

        return false;
    }

    protected function oauth_status_landing_url() {
        return add_query_arg('page_id', 394, home_url('/'));
    }

    protected function oauth_return_url($mode, $user_id) {
        if ($mode === 'link') {
            return $this->oauth_status_landing_url();
        }
        if ($mode === 'reset') {
            return $this->password_reset_landing_url();
        }
        return home_url('/');
    }

    protected function oauth_status_url($status, $message = '', $base_url = '') {
        $target = $base_url ? $base_url : $this->oauth_status_landing_url();
        $target = remove_query_arg(array('edb_discord_oauth', 'code', 'state', 'edb_oauth_status', 'edb_oauth_message'), $target);
        $args = array(
            'edb_oauth_status' => sanitize_key($status),
        );
        if ($message !== '') {
            $args['edb_oauth_message'] = sanitize_text_field($message);
        }
        return add_query_arg($args, $target);
    }

    protected function redirect_oauth_status($status, $message = '', $base_url = '') {
        wp_safe_redirect($this->oauth_status_url($status, $message, $base_url));
        exit;
    }

    protected function find_user_by_discord_or_email($me) {
        $direct = $this->find_user_by_discord_id_only(isset($me['id']) ? $me['id'] : '');
        if ($direct) {
            return $direct;
        }
        $settings = $this->settings();
        if (!empty($settings['auto_link_by_email']) && !empty($me['email'])) {
            $user = get_user_by('email', $me['email']);
            if ($user) {
                return (int) $user->ID;
            }
        }
        return 0;
    }

    protected function find_user_by_discord_id_only($discord_id) {
        if (!$discord_id) {
            return 0;
        }
        $users = get_users(array(
            'meta_key' => 'edb_discord_user_id',
            'meta_value' => (string) $discord_id,
            'number' => 1,
            'fields' => 'ids',
        ));
        return !empty($users) ? (int) $users[0] : 0;
    }

    protected function save_discord_identity($user_id, $me, $token_data) {
        update_user_meta($user_id, 'edb_discord_user_id', sanitize_text_field($me['id']));
        update_user_meta($user_id, 'edb_discord_username', sanitize_text_field(isset($me['username']) ? $me['username'] : ''));
        update_user_meta($user_id, 'edb_discord_global_name', sanitize_text_field(isset($me['global_name']) ? $me['global_name'] : ''));
        update_user_meta($user_id, 'edb_discord_avatar', sanitize_text_field(isset($me['avatar']) ? $me['avatar'] : ''));
        update_user_meta($user_id, 'edb_discord_linked', 1);
        update_user_meta($user_id, 'edb_discord_access_token', sanitize_text_field($token_data['access_token']));
        update_user_meta($user_id, 'edb_discord_refresh_token', sanitize_text_field(isset($token_data['refresh_token']) ? $token_data['refresh_token'] : ''));
        update_user_meta($user_id, 'edb_discord_scope', sanitize_text_field(isset($token_data['scope']) ? $token_data['scope'] : ''));
        if (!empty($token_data['expires_in'])) {
            update_user_meta($user_id, 'edb_discord_expires_at', time() + absint($token_data['expires_in']) - 60);
        }
        $this->sync_portal_discord_group_status($user_id);
        $this->refresh_user_guild_cache($user_id);
        $settings = $this->settings();
        if (!empty($settings['auto_join_primary_guild']) && $this->user_has_guild_join_scope($user_id)) {
            $this->force_join_primary_guild($user_id);
        }
        if (!empty($settings['auto_sync_on_link'])) {
            $this->sync_user_roles($user_id);
        }
        $this->queue_sync_request($user_id, 'discord_oauth');
    }

    protected function sync_portal_discord_group_status($user_id) {
        $user_id = absint($user_id);
        if ($user_id <= 0 || !class_exists('EUM_Discord_Groups')) {
            return;
        }
        try {
            (new EUM_Discord_Groups())->sync_user_group_status($user_id);
        } catch (Throwable $e) {
            error_log('[EDB] Could not sync Discord connection groups for user #' . $user_id . ': ' . $e->getMessage());
        }
    }

    public function handle_post_actions() {
        if (empty($_POST['edb_action']) || empty($_POST['edb_nonce'])) {
            return;
        }
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['edb_nonce'])), self::NONCE_ACTION)) {
            return;
        }
        $action = sanitize_text_field(wp_unslash($_POST['edb_action']));
        if (!current_user_can('manage_options') && !$this->can_message()) {
            wp_die('Not allowed.');
        }

        if (($action === 'send_message_ui' || $action === 'send_discord_ui') && $this->can_message()) {
            $user_ids = $this->posted_selected_user_ids();
            if (empty($user_ids)) {
                $user_ids = array_map('absint', isset($_POST['edb_user_ids']) ? (array) wp_unslash($_POST['edb_user_ids']) : array());
            }
            $discord_ids = $this->posted_selected_discord_ids();
            if (empty($discord_ids)) {
                $discord_ids = array_map('sanitize_text_field', isset($_POST['edb_discord_ids']) ? (array) wp_unslash($_POST['edb_discord_ids']) : array());
            }
            $message = wp_kses_post(wp_unslash(isset($_POST['edb_message']) ? $_POST['edb_message'] : ''));
            $delivery = sanitize_key(wp_unslash(isset($_POST['edb_delivery']) ? $_POST['edb_delivery'] : 'discord'));
            $subject = sanitize_text_field(wp_unslash(isset($_POST['edb_email_subject']) ? $_POST['edb_email_subject'] : ''));
            if ($message === '') {
                $this->redirect_with_notice('Please enter a message first.');
            }
            if (empty($user_ids) && empty($discord_ids)) {
                $this->redirect_with_notice('Select at least one person first.');
            }

            $sent_any = false;
            if (in_array($delivery, array('discord', 'both'), true)) {
                $resolved = $this->resolve_discord_targets($user_ids, $discord_ids);
                foreach ($resolved as $target) {
                    $body = $this->apply_placeholders($message, $target['user_id'], get_current_user_id());
                    $this->send_dm($target['discord_id'], $body, get_current_user_id());
                    $sent_any = true;
                }
            }

            if (in_array($delivery, array('wordpress', 'both'), true) && !empty($user_ids)) {
                $headers = array('Content-Type: text/html; charset=UTF-8');
                $mail_subject = $subject !== '' ? $subject : 'Message from ' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
                foreach ($user_ids as $user_id) {
                    $user = get_userdata($user_id);
                    if (!$user || !$user->user_email) {
                        continue;
                    }
                    $body = nl2br($this->apply_placeholders($message, $user_id, get_current_user_id()));
                    wp_mail($user->user_email, $mail_subject, $body, $headers);
                    $sent_any = true;
                }
            }

            $this->redirect_with_notice($sent_any ? 'Messages sent.' : 'No recipients were eligible for that delivery type.');
        }

        if ($action === 'channel_post_ui' && $this->can_message()) {
            $channel_id = sanitize_text_field(wp_unslash(isset($_POST['edb_channel_id']) ? $_POST['edb_channel_id'] : ''));
            $message = wp_kses_post(wp_unslash(isset($_POST['edb_message']) ? $_POST['edb_message'] : ''));
            if ($channel_id && $message) {
                $this->post_channel_message($channel_id, $message);
            }
            $this->redirect_with_notice('Channel post sent.');
        }

        if ($action === 'member_utility_ui' && $this->can_message()) {
            $user_ids = $this->posted_selected_user_ids();
            if (empty($user_ids)) {
                $user_ids = array_filter(array_map('absint', isset($_POST['edb_user_ids']) ? (array) wp_unslash($_POST['edb_user_ids']) : array()));
            }
            $utility = sanitize_key(wp_unslash(isset($_POST['edb_utility']) ? $_POST['edb_utility'] : ''));
            if (empty($user_ids)) {
                $this->redirect_with_notice('Select at least one portal user first.');
            }
            if ($utility === 'refresh_guilds') {
                foreach ($user_ids as $user_id) {
                    $this->refresh_user_guild_cache($user_id);
                }
                $this->redirect_with_notice('Guild lists refreshed.');
            }
            if ($utility === 'force_join' || $utility === 'force_join_guild') {
                $target_guild_id = $this->resolve_target_guild_id(isset($_POST['edb_target_guild_id']) ? wp_unslash($_POST['edb_target_guild_id']) : '', isset($_POST['edb_target_guild_id_custom']) ? wp_unslash($_POST['edb_target_guild_id_custom']) : '');
                $joined = 0;
                $needs_permission = 0;
                $failed = 0;
                foreach ($user_ids as $user_id) {
                    if (!$this->user_has_guild_join_scope($user_id)) {
                        $needs_permission++;
                    } elseif ($this->force_join_guild($user_id, $target_guild_id)) {
                        $joined++;
                    } else {
                        $failed++;
                    }
                }
                $guild_label = $target_guild_id ? $target_guild_id : 'primary guild';
                $notice = 'Guild join result for ' . $guild_label . ': ' . $joined . ' added';
                if ($needs_permission) {
                    $notice .= '; ' . $needs_permission . ' need to reconnect Discord in the portal and approve guilds.join';
                }
                if ($failed) {
                    $notice .= '; ' . $failed . ' could not be added';
                }
                $this->redirect_with_notice($notice . '.');
            }
            if ($utility === 'sync_roles') {
                foreach ($user_ids as $user_id) {
                    $this->sync_user_roles($user_id);
                }
                $this->redirect_with_notice('Selected users synced.');
            }
        }

        if ($action === 'role_rule_tester_ui' && $this->can_message()) {
            $selected_user_ids = $this->posted_selected_user_ids();
            $user_id = absint(isset($_POST['edb_test_user_id']) ? wp_unslash($_POST['edb_test_user_id']) : 0);
            if ($user_id <= 0 && !empty($selected_user_ids)) {
                $user_id = (int) reset($selected_user_ids);
            }
            if ($user_id <= 0) {
                $this->redirect_with_notice('Choose one linked portal user to test.');
            }
            $target_guild_id = $this->resolve_target_guild_id(isset($_POST['edb_target_guild_id']) ? wp_unslash($_POST['edb_target_guild_id']) : '', isset($_POST['edb_target_guild_id_custom']) ? wp_unslash($_POST['edb_target_guild_id_custom']) : '');
            $this->store_role_tester_report($this->build_role_tester_report($user_id, $target_guild_id));
            $this->redirect_with_notice('Role rule test generated.');
        }

        if ($action === 'sync_all_roles' && current_user_can('manage_options')) {
            $this->run_dynamic_role_sync();
            $this->redirect_with_notice('Role sync complete.');
        }

        if ($action === 'refresh_all_guilds' && current_user_can('manage_options')) {
            foreach ($this->get_linked_users() as $user) {
                $this->refresh_user_guild_cache($user->ID);
            }
            $this->redirect_with_notice('Guild directory refreshed.');
        }
    }

    protected function posted_selected_user_ids() {
        $raw = isset($_POST['edb_selected_user_ids']) ? sanitize_text_field(wp_unslash($_POST['edb_selected_user_ids'])) : '';
        if ($raw === '') {
            return array();
        }
        return array_values(array_filter(array_map('absint', explode(',', $raw))));
    }

    protected function posted_selected_discord_ids() {
        $raw = isset($_POST['edb_selected_discord_ids']) ? sanitize_text_field(wp_unslash($_POST['edb_selected_discord_ids'])) : '';
        if ($raw === '') {
            return array();
        }
        return array_values(array_filter(array_map('sanitize_text_field', explode(',', $raw))));
    }

    protected function redirect_with_notice($notice) {
        $ref = wp_get_referer();
        if (!$ref) {
            $ref = admin_url('admin.php?page=edb-discord');
        }
        wp_safe_redirect(add_query_arg('edb_notice', rawurlencode($notice), $ref));
        exit;
    }

    protected function resolve_discord_targets($user_ids, $discord_ids) {
        $resolved = array();
        foreach ($user_ids as $uid) {
            $did = get_user_meta(absint($uid), 'edb_discord_user_id', true);
            if ($did) {
                $resolved[] = array('user_id' => absint($uid), 'discord_id' => $did);
            }
        }
        foreach ($discord_ids as $did) {
            if ($did === '') {
                continue;
            }
            $resolved[] = array('user_id' => $this->find_user_by_discord_id_only($did), 'discord_id' => $did);
        }
        $uniq = array();
        $out = array();
        foreach ($resolved as $row) {
            $key = $row['discord_id'];
            if ($key && empty($uniq[$key])) {
                $uniq[$key] = true;
                $out[] = $row;
            }
        }
        return $out;
    }

    protected function build_discord_oauth_url($mode, $user_id) {
        $settings = $this->settings();
        $state = $this->create_oauth_state($mode, $user_id);
        return add_query_arg(array(
            'client_id' => $settings['client_id'],
            'redirect_uri' => $settings['redirect_uri'],
            'response_type' => 'code',
            'scope' => $this->required_oauth_scopes($settings['oauth_scopes']),
            'prompt' => 'consent',
            'state' => $state,
        ), 'https://discord.com/oauth2/authorize');
    }

    protected function required_oauth_scopes($configured_scopes) {
        $scopes = preg_split('/\\s+/', trim((string) $configured_scopes), -1, PREG_SPLIT_NO_EMPTY);
        $scopes = is_array($scopes) ? $scopes : array();
        foreach (array('identify', 'guilds.join') as $required_scope) {
            if (!in_array($required_scope, $scopes, true)) {
                $scopes[] = $required_scope;
            }
        }
        return implode(' ', array_values(array_unique($scopes)));
    }

    protected function user_has_guild_join_scope($user_id) {
        $scope_string = (string) get_user_meta((int) $user_id, 'edb_discord_scope', true);
        $scopes = preg_split('/\\s+/', trim($scope_string), -1, PREG_SPLIT_NO_EMPTY);
        return is_array($scopes) && in_array('guilds.join', $scopes, true);
    }

    protected function oauth_url($mode, $user_id = 0) {
        $args = array(
            'edb_discord_oauth' => 'start',
            'mode' => sanitize_key($mode),
        );
        if ($mode !== 'link' && (int) $user_id > 0) {
            $args['user_id'] = (int) $user_id;
        }
        return add_query_arg($args, home_url('/'));
    }

    protected function discord_api($method, $path, $body = null, $bot = true, $token_override = '') {
        $settings = $this->settings();
        $headers = array(
            'Authorization' => $bot ? 'Bot ' . $settings['bot_token'] : 'Bearer ' . $token_override,
            'Content-Type' => 'application/json',
        );
        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => 20,
        );
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }
        $resp = wp_remote_request('https://discord.com/api/v10' . $path, $args);
        return array(
            'code' => wp_remote_retrieve_response_code($resp),
            'body' => json_decode(wp_remote_retrieve_body($resp), true),
            'raw' => wp_remote_retrieve_body($resp),
        );
    }

    protected function discord_get($path, $access_token, $use_bot) {
        $resp = wp_remote_get('https://discord.com/api/v10' . $path, array(
            'headers' => array(
                'Authorization' => $use_bot ? 'Bot ' . $this->settings()['bot_token'] : 'Bearer ' . $access_token,
            ),
            'timeout' => 20,
        ));
        return json_decode(wp_remote_retrieve_body($resp), true);
    }

    protected function refresh_user_token($user_id) {
        $settings = $this->settings();
        $refresh = get_user_meta($user_id, 'edb_discord_refresh_token', true);
        if (!$refresh) {
            return false;
        }
        $token = wp_remote_post('https://discord.com/api/oauth2/token', array(
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
            'body' => array(
                'client_id' => $settings['client_id'],
                'client_secret' => $settings['client_secret'],
                'grant_type' => 'refresh_token',
                'refresh_token' => $refresh,
            ),
            'timeout' => 20,
        ));
        $data = json_decode(wp_remote_retrieve_body($token), true);
        if (empty($data['access_token'])) {
            return false;
        }
        update_user_meta($user_id, 'edb_discord_access_token', sanitize_text_field($data['access_token']));
        if (!empty($data['refresh_token'])) {
            update_user_meta($user_id, 'edb_discord_refresh_token', sanitize_text_field($data['refresh_token']));
        }
        if (!empty($data['expires_in'])) {
            update_user_meta($user_id, 'edb_discord_expires_at', time() + absint($data['expires_in']) - 60);
        }
        return $data['access_token'];
    }

    protected function get_user_access_token($user_id) {
        $access = get_user_meta($user_id, 'edb_discord_access_token', true);
        $expires_at = absint(get_user_meta($user_id, 'edb_discord_expires_at', true));
        if (!$access || ($expires_at && $expires_at < time())) {
            return $this->refresh_user_token($user_id);
        }
        return $access;
    }

    public function get_user_guilds($user_id) {
        $access = $this->get_user_access_token($user_id);
        if (!$access) {
            return array();
        }
        $guilds = $this->discord_get('/users/@me/guilds', $access, false);
        return is_array($guilds) ? $guilds : array();
    }

    protected function get_cached_guilds($user_id) {
        $guilds = get_user_meta($user_id, self::GUILD_CACHE_META, true);
        return is_array($guilds) ? $guilds : array();
    }

    protected function refresh_user_guild_cache($user_id) {
        $guilds = $this->get_user_guilds($user_id);
        $sanitized = array();
        foreach ($guilds as $guild) {
            if (empty($guild['id'])) {
                continue;
            }
            $sanitized[] = array(
                'id' => sanitize_text_field($guild['id']),
                'name' => sanitize_text_field(isset($guild['name']) ? $guild['name'] : ''),
            );
        }
        update_user_meta($user_id, self::GUILD_CACHE_META, $sanitized);
        update_user_meta($user_id, self::GUILD_CACHE_AT_META, time());
        return $sanitized;
    }

    public function force_join_primary_guild($user_id) {
        return $this->force_join_guild($user_id, '');
    }

    public function force_join_guild($user_id, $guild_id = '') {
        $settings = $this->settings();
        $target_guild_id = $guild_id ? sanitize_text_field((string) $guild_id) : sanitize_text_field((string) $settings['guild_id']);
        $discord_id = get_user_meta($user_id, 'edb_discord_user_id', true);
        if (!$this->user_has_guild_join_scope($user_id)) {
            return false;
        }
        $access = $this->get_user_access_token($user_id);
        if (!$target_guild_id || !$discord_id || !$access) {
            return false;
        }
        $resp = $this->discord_api('PUT', '/guilds/' . $target_guild_id . '/members/' . $discord_id, array(
            'access_token' => $access,
        ), true);
        if ($resp['code'] === 201 || $resp['code'] === 204) {
            $this->refresh_user_guild_cache($user_id);
            $target_guild_id = (string)$target_guild_id;
            $discord_id = (string)$discord_id;
            if (isset($this->guild_member_id_cache[$target_guild_id])) {
                $this->guild_member_id_cache[$target_guild_id][$discord_id] = true;
            }
            return true;
        }
        return false;
    }

    protected function guild_member_exists($guild_id, $discord_id) {
        $guild_id = sanitize_text_field((string) $guild_id);
        $discord_id = sanitize_text_field((string) $discord_id);
        if ($guild_id === '' || $discord_id === '') {
            return false;
        }
        $members = $this->guild_member_id_map($guild_id);
        return isset($members[$discord_id]);
    }

    protected function guild_member_id_map($guild_id) {
        $guild_id = sanitize_text_field((string) $guild_id);
        if ($guild_id === '') {
            return array();
        }
        if (isset($this->guild_member_id_cache[$guild_id])) {
            return $this->guild_member_id_cache[$guild_id];
        }
        $members = $this->get_guild_members($guild_id, 100);
        $ids = array();
        foreach ((array) $members as $member) {
            $discord_id = (string) ($member['user']['id'] ?? '');
            if ($discord_id !== '') {
                $ids[$discord_id] = true;
            }
        }
        $this->guild_member_id_cache[$guild_id] = $ids;
        return $ids;
    }

    protected function ensure_user_in_guild($user_id, $guild_id) {
        $user_id = (int) $user_id;
        $guild_id = sanitize_text_field((string) $guild_id);
        $discord_id = trim((string) get_user_meta($user_id, 'edb_discord_user_id', true));
        if ($user_id <= 0 || $guild_id === '' || $discord_id === '') {
            return false;
        }
        if ($this->guild_member_exists($guild_id, $discord_id)) {
            return true;
        }
        return $this->force_join_guild($user_id, $guild_id);
    }

    public function send_dm($discord_user_id, $message, $actor_user_id = 0) {
        $settings = $this->settings();
        if (!empty($settings['dm_prefix_sender']) && $actor_user_id) {
            $actor = get_userdata($actor_user_id);
            if ($actor) {
                $message = '[Sent by ' . $actor->display_name . ' <' . $actor->user_email . '>]' . "\n\n" . $message;
            }
        }
        $bot_result = $this->call_bot_service('/edb/v1/send-dm', array(
            'discord_user_id' => (string) $discord_user_id,
            'message' => (string) $message,
            'actor_user_id' => (int) $actor_user_id,
        ));
        if (!empty($bot_result['success'])) {
            return true;
        }
        $channel = $this->discord_api('POST', '/users/@me/channels', array('recipient_id' => (string) $discord_user_id), true);
        if (empty($channel['body']['id'])) {
            return false;
        }
        $resp = $this->discord_api('POST', '/channels/' . $channel['body']['id'] . '/messages', array('content' => $message), true);
        return in_array($resp['code'], array(200, 201), true);
    }

    public function post_channel_message($channel_id, $message) {
        $bot_result = $this->call_bot_service('/edb/v1/send-channel-message', array(
            'channel_id' => (string) $channel_id,
            'message' => (string) $message,
        ));
        if (!empty($bot_result['success'])) {
            return true;
        }
        $resp = $this->discord_api('POST', '/channels/' . $channel_id . '/messages', array('content' => $message), true);
        return in_array($resp['code'], array(200, 201), true);
    }

    protected function bot_service_base_url() {
        $settings = $this->settings();
        $base = trim((string) ($settings['bot_service_url'] ?? ''));
        return $base !== '' ? untrailingslashit($base) : '';
    }

    protected function call_bot_service($path, array $payload = array()) {
        $base = $this->bot_service_base_url();
        if ($base === '') {
            return array('success' => false, 'message' => 'Bot service URL is not configured.');
        }
        $secret = (string) get_option(self::RESET_SECRET_OPTION, '');
        if ($secret === '') {
            return array('success' => false, 'message' => 'Bot shared secret is not configured.');
        }
        $url = $base . '/' . ltrim((string) $path, '/');
        $response = wp_remote_post($url, array(
            'timeout' => 12,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-EDB-Secret' => $secret,
            ),
            'body' => wp_json_encode($payload),
        ));
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message(), 'url' => $url);
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            $body = array();
        }
        $body['code'] = $code;
        $body['url'] = $url;
        if ($code < 200 || $code >= 300) {
            $body['success'] = false;
        }
        return $body;
    }

    protected function get_user_data_map($user_id) {
        $user = $user_id ? get_userdata($user_id) : null;
        return array(
            'user_name' => $user ? $user->display_name : '',
            'user_email' => $user ? $user->user_email : '',
            'user_first_name' => $user ? get_user_meta($user_id, 'first_name', true) : '',
            'user_last_name' => $user ? get_user_meta($user_id, 'last_name', true) : '',
            'job_path' => $this->user_job_path_label($user_id),
            'company_division' => $user ? (string) get_user_meta($user_id, 'job_company', true) : '',
        );
    }

    protected function apply_placeholders($message, $target_user_id, $actor_user_id) {
        $target = $this->get_user_data_map($target_user_id);
        $actor = $this->get_user_data_map($actor_user_id);
        foreach ($target as $key => $value) {
            $message = str_replace('{{' . $key . '}}', (string) $value, $message);
        }
        foreach ($actor as $key => $value) {
            $message = str_replace('{{actor_' . $key . '}}', (string) $value, $message);
        }
        if ($target_user_id) {
            preg_match_all('/\{\{user_meta:([^}]+)\}\}/', $message, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $meta_key) {
                    $message = str_replace('{{user_meta:' . $meta_key . '}}', (string) get_user_meta($target_user_id, $meta_key, true), $message);
                }
            }
        }
        return $message;
    }

    protected function get_rule_matches($rules, $user_id) {
        $matched_role_ids = array();
        $user = get_userdata($user_id);
        if (!$user) {
            return $matched_role_ids;
        }
        $user_groups = $this->user_term_names($user_id, 'eum_user_group');
        $projects = $this->user_term_names($user_id, 'eum_project');
        $job_path = $this->user_job_path_label($user_id);
        $terminated = (string) get_user_meta($user_id, 'status', true) . ' ' . (string) get_user_meta($user_id, 'system:eum_terminated', true);
        foreach ((array) $rules as $rule) {
            if (empty($rule['role_id'])) {
                continue;
            }
            if ($this->rule_matches_user($rule, $user_id, $user, $user_groups, $projects, $job_path, $terminated)) {
                $matched_role_ids[] = (string) $rule['role_id'];
            }
        }
        return array_values(array_unique(array_filter($matched_role_ids)));
    }

    protected function rule_role_ids($rules) {
        $role_ids = array();
        foreach ((array) $rules as $rule) {
            if (!is_array($rule) || empty($rule['role_id'])) {
                continue;
            }
            $role_ids[] = (string) $rule['role_id'];
        }
        return array_values(array_unique(array_filter($role_ids)));
    }

    protected function rule_matches_user($rule, $user_id, $user, $user_groups, $projects, $job_path, $terminated) {
        $inspection = $this->inspect_rule_match($rule, $user_id, $user, $user_groups, $projects, $job_path, $terminated);
        return !empty($inspection['matched']);
    }

    protected function inspect_rule_match($rule, $user_id, $user, $user_groups, $projects, $job_path, $terminated) {
        $employment_status = $this->user_employment_status($user_id);
        $reasons = array();
        if (!empty($rule['linked_only']) && !get_user_meta($user_id, 'edb_discord_user_id', true)) {
            $reasons[] = 'User is not Discord linked.';
        }
        if (!empty($rule['wp_role']) && !in_array((string) $rule['wp_role'], (array) $user->roles, true)) {
            $reasons[] = 'WordPress role ' . (string) $rule['wp_role'] . ' was not found on the user.';
        }
        if (!empty($rule['eum_group']) && !in_array((string) $rule['eum_group'], (array) $user_groups, true)) {
            $reasons[] = 'EUM group ' . (string) $rule['eum_group'] . ' was not found on the user.';
        }
        if (!empty($rule['project']) && !in_array((string) $rule['project'], (array) $projects, true)) {
            $reasons[] = 'Project ' . (string) $rule['project'] . ' was not found on the user.';
        }
        if (!empty($rule['job_path_contains']) && stripos($job_path, (string) $rule['job_path_contains']) === false) {
            $reasons[] = 'Job path did not contain ' . (string) $rule['job_path_contains'] . '.';
        }
        if (!empty($rule['exclude_if_terminated']) && $employment_status === 'terminated') {
            $reasons[] = 'Rule excludes terminated users, and this user is terminated.';
        }
        if (!empty($rule['terminated_only']) && $employment_status !== 'terminated') {
            $reasons[] = 'Rule requires a terminated user.';
        }
        if (!empty($rule['active_only']) && $employment_status !== 'active') {
            $reasons[] = 'Rule requires an active user.';
        }
        if (!empty($rule['employment_status']) && sanitize_key((string) $rule['employment_status']) !== $employment_status) {
            $reasons[] = 'employment_status expected ' . sanitize_key((string) $rule['employment_status']) . ', but the plugin resolved ' . $employment_status . '.';
        }
        if (!empty($rule['currently_clocked_in']) && !$this->user_is_currently_clocked_in($user_id)) {
            $reasons[] = 'User is not currently clocked in.';
        }
        if (!empty($rule['scheduled_now']) && !$this->user_is_scheduled_now($user_id)) {
            $reasons[] = 'User is not scheduled right now.';
        }
        if (!empty($rule['user_meta_key'])) {
            $meta_value = get_user_meta($user_id, $rule['user_meta_key'], true);
            if ((string) $meta_value !== (string) $rule['user_meta_value']) {
                $reasons[] = 'Meta ' . (string) $rule['user_meta_key'] . ' expected ' . (string) $rule['user_meta_value'] . ', but found ' . (string) $meta_value . '.';
            }
        }
        return array(
            'matched' => empty($reasons),
            'reasons' => $reasons,
            'employment_status' => $employment_status,
        );
    }

    public function run_dynamic_role_sync() {
        foreach ($this->get_linked_users() as $user) {
            $this->sync_user_roles($user->ID);
        }
        $this->sync_primary_guild_community_roles();
    }

    protected function get_linked_users() {
        return get_users(array(
            'meta_key' => 'edb_discord_user_id',
            'meta_compare' => 'EXISTS',
            'orderby' => 'display_name',
            'order' => 'ASC',
        ));
    }

    protected function sync_user_roles($user_id) {
        $settings = $this->settings();
        $discord_id = get_user_meta($user_id, 'edb_discord_user_id', true);
        if (!$discord_id) {
            return false;
        }
        $sync_succeeded = true;
        $is_terminated = $this->user_employment_status($user_id) === 'terminated';

        if (!empty($settings['guild_id'])) {
            $primary = $this->desired_primary_guild_roles($user_id);
            $primary_rules = json_decode($settings['role_rules_json'], true);
            $primary_rules = is_array($primary_rules) ? $primary_rules : array();
            $rule_matches = $is_terminated ? array() : $this->get_rule_matches($primary_rules, $user_id);
            $managed_pool = array_unique(array_merge($primary['managed_pool'], $this->rule_role_ids($primary_rules)));
            $desired = array_unique(array_merge($primary['desired'], $rule_matches));
            $primary_sync_succeeded = !array_key_exists('sync_succeeded', $primary) || !empty($primary['sync_succeeded']);
            $member_ready = $is_terminated
                ? $this->guild_member_exists($settings['guild_id'], $discord_id)
                : $this->ensure_user_in_guild($user_id, $settings['guild_id']);
            $primary_roles_applied = $member_ready
                ? $this->apply_roles_to_member($settings['guild_id'], $discord_id, $desired, $managed_pool)
                : $is_terminated;
            if ($primary_roles_applied && $primary_sync_succeeded) {
                update_user_meta($user_id, self::PRIMARY_ROLE_NAMES_META, $primary['labels']);
                update_user_meta($user_id, self::PRIMARY_ROLE_IDS_META, $desired);
                update_user_meta($user_id, self::PRIMARY_ROLE_SYNC_AT_META, time());
                delete_user_meta($user_id, self::PRIMARY_ROLE_SYNC_ERROR_META);
            } else {
                $error = !$primary_sync_succeeded
                    ? 'Discord could not create one or more managed roles.'
                    : ((!$member_ready && !$is_terminated)
                        ? 'Discord could not restore server membership. The linked user must have granted the guilds.join permission.'
                        : 'Discord rejected the primary guild role update.');
                update_user_meta($user_id, self::PRIMARY_ROLE_SYNC_ERROR_META, current_time('mysql') . ' - ' . $error);
                $sync_succeeded = false;
            }
        }

        $secondary_rules = json_decode($settings['secondary_guild_rules_json'], true);
        if (is_array($secondary_rules)) {
            $by_guild = array();
            foreach ($secondary_rules as $rule) {
                if (empty($rule['guild_id']) || empty($rule['role_id'])) {
                    continue;
                }
                $by_guild[$rule['guild_id']][] = $rule;
            }
            foreach ($by_guild as $guild_id => $rules) {
                $desired = $is_terminated ? array() : $this->get_rule_matches($rules, $user_id);
                $managed = array();
                foreach ($rules as $rule) {
                    $managed[] = (string) $rule['role_id'];
                }
                $member_ready = $is_terminated
                    ? $this->guild_member_exists($guild_id, $discord_id)
                    : $this->ensure_user_in_guild($user_id, $guild_id);
                if ($member_ready && !$this->apply_roles_to_member($guild_id, $discord_id, $desired, array_values(array_unique(array_filter($managed))))) {
                    $sync_succeeded = false;
                } elseif (!$member_ready && !$is_terminated) {
                    $sync_succeeded = false;
                }
            }
        }
        return $sync_succeeded;
    }

    protected function desired_primary_guild_roles($user_id) {
        $settings = $this->settings();
        $guild_id = (string) $settings['guild_id'];
        $desired = array();
        $managed = array();
        $labels = array();
        $role_setup_succeeded = true;
        if (empty($guild_id)) {
            return array('desired' => array(), 'managed_pool' => array(), 'labels' => array(), 'sync_succeeded' => true);
        }

        $is_terminated = $this->user_employment_status($user_id) === 'terminated';

        if (!empty($settings['auto_create_primary_roles'])) {
            $role_names = array();
            $desired_role_names = array();
            $add_role_name = function($role_name, $should_apply = false) use (&$role_names, &$desired_role_names, &$labels) {
                $role_name = trim((string) $role_name);
                if ($role_name === '') {
                    return;
                }
                $role_names[] = $role_name;
                if ($should_apply) {
                    $desired_role_names[] = $role_name;
                    $labels[] = $role_name;
                }
            };

            $add_role_name(isset($settings['primary_role_community']) ? $settings['primary_role_community'] : 'Community', false);
            $add_role_name(isset($settings['primary_role_alumni']) ? $settings['primary_role_alumni'] : 'JAS Enterprises Alumni', $is_terminated);

            if (!$is_terminated) {
                $add_role_name(isset($settings['primary_role_authenticated']) ? $settings['primary_role_authenticated'] : 'Authenticated Portal User', true);
                $add_role_name(isset($settings['primary_role_clocked_in']) ? $settings['primary_role_clocked_in'] : 'Clocked In', $this->user_is_currently_clocked_in($user_id));
                $add_role_name(isset($settings['primary_role_scheduled_now']) ? $settings['primary_role_scheduled_now'] : 'Scheduled Now', $this->user_is_scheduled_now($user_id));
                foreach ($this->user_taxonomy_id_role_names($user_id, 'eum_job_path', isset($settings['primary_role_job_path_prefix']) ? $settings['primary_role_job_path_prefix'] : 'Path: ', true) as $role_name) {
                    $add_role_name($role_name, true);
                }
                foreach ($this->user_taxonomy_id_role_names($user_id, 'eum_user_group', isset($settings['primary_role_group_prefix']) ? $settings['primary_role_group_prefix'] : 'Group: ', false) as $role_name) {
                    $add_role_name($role_name, true);
                }
                foreach ($this->user_taxonomy_id_role_names($user_id, 'eum_project', isset($settings['primary_role_project_prefix']) ? $settings['primary_role_project_prefix'] : 'Project: ', false) as $role_name) {
                    $add_role_name($role_name, true);
                }
            }

            foreach (array_unique(array_filter($role_names)) as $role_name) {
                $role_id = $this->ensure_guild_role($guild_id, $role_name);
                if ($role_id) {
                    $managed[] = $role_id;
                    if (in_array($role_name, $desired_role_names, true)) {
                        $desired[] = $role_id;
                    }
                } else {
                    $role_setup_succeeded = false;
                }
            }
        } elseif ($is_terminated && !empty($settings['primary_role_alumni'])) {
            $alumni_role_id = $this->find_guild_role_id($guild_id, $settings['primary_role_alumni']);
            if ($alumni_role_id !== '') {
                $desired[] = $alumni_role_id;
                $managed[] = $alumni_role_id;
                $labels[] = $settings['primary_role_alumni'];
            }
        } elseif (!$is_terminated) {
            foreach ($this->user_taxonomy_id_role_names($user_id, 'eum_user_group', '', false) as $role_name) {
                $role_id = $this->find_guild_role_id($guild_id, $role_name);
                if ($role_id !== '') {
                    $desired[] = $role_id;
                    $managed[] = $role_id;
                    $labels[] = $role_name;
                }
            }
        }
        $managed = array_merge($managed, $this->primary_managed_role_ids($guild_id, $settings));

        $mapped = $this->primary_mapped_roles_for_user($user_id, false);
        if ($is_terminated) {
            $mapped['desired'] = array();
            $mapped['labels'] = array();
        }
        $desired = array_merge($desired, $mapped['desired']);
        $managed = array_merge($managed, $mapped['managed_pool']);
        $labels = array_merge($labels, $mapped['labels']);

        $labels = array_values(array_unique(array_filter($labels)));
        return array(
            'desired' => array_values(array_unique(array_filter($desired))),
            'managed_pool' => array_values(array_unique(array_filter($managed))),
            'labels' => $labels,
            'sync_succeeded' => $role_setup_succeeded,
        );
    }

    protected function primary_managed_role_ids($guild_id, $settings) {
        $managed = array();
        $exact_names = array(
            isset($settings['primary_role_authenticated']) ? $settings['primary_role_authenticated'] : '',
            isset($settings['primary_role_community']) ? $settings['primary_role_community'] : '',
            isset($settings['primary_role_alumni']) ? $settings['primary_role_alumni'] : '',
            isset($settings['primary_role_clocked_in']) ? $settings['primary_role_clocked_in'] : '',
            isset($settings['primary_role_scheduled_now']) ? $settings['primary_role_scheduled_now'] : '',
            'Discord Connected',
            'Discord Not Connected',
        );
        $exact_names = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $exact_names)))));
        $prefixes = array(
            isset($settings['primary_role_job_path_prefix']) ? $settings['primary_role_job_path_prefix'] : '',
            isset($settings['primary_role_group_prefix']) ? $settings['primary_role_group_prefix'] : '',
            isset($settings['primary_role_project_prefix']) ? $settings['primary_role_project_prefix'] : '',
        );
        $prefixes = array_values(array_filter(array_map('strval', $prefixes), function($prefix) {
            return $prefix !== '';
        }));

        foreach ($this->get_guild_roles($guild_id) as $role) {
            if (empty($role['id']) || !isset($role['name'])) {
                continue;
            }
            $name = (string) $role['name'];
            if (in_array($name, $exact_names, true)) {
                $managed[] = (string) $role['id'];
                continue;
            }
            foreach ($prefixes as $prefix) {
                if ($prefix !== '' && strpos($name, $prefix) === 0) {
                    $managed[] = (string) $role['id'];
                    break;
                }
            }
        }
        return array_values(array_unique(array_filter($managed)));
    }

    protected function primary_mapped_roles_for_user($user_id, $direct_only = false) {
        $rows = $this->primary_role_map_rows();
        if (empty($rows)) {
            return array('desired' => array(), 'managed_pool' => array(), 'labels' => array());
        }
        $settings = $this->settings();
        $guild_id = isset($settings['guild_id']) ? (string) $settings['guild_id'] : '';
        $role_names = $guild_id !== '' ? $this->guild_role_name_map($guild_id) : array();
        $desired = array();
        $managed = array();
        $labels = array();

        foreach ($rows as $row) {
            $managed[] = $row['role_id'];
            if ($direct_only && $row['category'] !== 'user') {
                continue;
            }
            if ($this->primary_role_map_row_matches_user($row, $user_id)) {
                $desired[] = $row['role_id'];
                $labels[] = $this->primary_role_map_label($row, $role_names);
            }
        }

        return array(
            'desired' => array_values(array_unique(array_filter($desired))),
            'managed_pool' => array_values(array_unique(array_filter($managed))),
            'labels' => array_values(array_unique(array_filter($labels))),
        );
    }

    protected function primary_role_map_label($row, $role_names) {
        if (!empty($role_names[$row['role_id']])) {
            return (string) $role_names[$row['role_id']];
        }
        $label = ucfirst(str_replace('_', ' ', (string) $row['category']));
        if ((string) $row['key'] !== '') {
            $label .= ': ' . (string) $row['key'];
        }
        return $label . ' -> ' . (string) $row['role_id'];
    }

    protected function primary_role_map_rows() {
        $settings = $this->settings();
        return $this->parse_primary_role_map_text(isset($settings['primary_role_maps_text']) ? $settings['primary_role_maps_text'] : '');
    }

    protected function primary_role_map_role_ids() {
        $role_ids = array();
        foreach ($this->primary_role_map_rows() as $row) {
            if (!empty($row['role_id'])) {
                $role_ids[] = (string) $row['role_id'];
            }
        }
        return array_values(array_unique(array_filter($role_ids)));
    }

    protected function parse_primary_role_map_text($text) {
        $rows = array();
        $text = str_replace(array("\r\n", "\r"), "\n", (string) $text);
        foreach (preg_split('/[\n,]+/', $text) as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '' || strpos($entry, '#') === 0 || strpos($entry, '//') === 0) {
                continue;
            }
            $row = $this->parse_primary_role_map_entry($entry);
            if ($row) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    protected function parse_primary_role_map_entry($entry) {
        if (!preg_match('/^(.*?)\s*=\s*(.+)$/', (string) $entry, $matches)) {
            return null;
        }
        $role_id = $this->extract_discord_snowflake($matches[2]);
        if ($role_id === '') {
            return null;
        }
        $left = trim((string) $matches[1]);
        $left = trim($left, "{} \t\n\r\0\x0B");
        $left = preg_replace('/\s+/', ' ', $left);
        if ($left === '') {
            return null;
        }
        if (!preg_match('/^(role|wp[_ -]?role|wordpress[_ -]?role|group|eum[_ -]?group|user[_ -]?group|job\s*path\s*\+\s*title|job[_ -]?path[_ -]?title|path\s*\+\s*title|path[_ -]?title|job[_ -]?title|title|job[_ -]?path|job|path|project|location|user|event)\b\s*(?::|=)?\s*(.*)$/i', $left, $left_matches)) {
            return null;
        }
        $category = $this->normalize_role_map_category($left_matches[1]);
        if ($category === '') {
            return null;
        }
        return array(
            'category' => $category,
            'key' => trim((string) $left_matches[2]),
            'role_id' => $role_id,
            'source' => (string) $entry,
        );
    }

    protected function extract_discord_snowflake($value) {
        $value = trim((string) $value);
        $value = trim($value, "{} \t\n\r\0\x0B");
        if (preg_match('/([0-9]{5,})/', $value, $matches)) {
            return (string) $matches[1];
        }
        return '';
    }

    protected function normalize_role_map_category($category) {
        $category = strtolower(str_replace(array('-', ' ', '+'), '_', trim((string) $category)));
        $category = preg_replace('/_+/', '_', $category);
        if (in_array($category, array('role', 'wp_role', 'wordpress_role'), true)) {
            return 'wp_role';
        }
        if (in_array($category, array('group', 'eum_group', 'user_group'), true)) {
            return 'group';
        }
        if (in_array($category, array('job', 'job_path', 'path'), true)) {
            return 'job_path';
        }
        if (in_array($category, array('job_path_title', 'path_title', 'job_title', 'title'), true)) {
            return 'path_title';
        }
        if (in_array($category, array('project', 'location', 'user', 'event'), true)) {
            return $category;
        }
        return '';
    }

    protected function primary_role_map_row_matches_user($row, $user_id) {
        $user = get_userdata($user_id);
        if (!$user || empty($row['category'])) {
            return false;
        }
        $key = isset($row['key']) ? (string) $row['key'] : '';
        switch ($row['category']) {
            case 'wp_role':
                $values = array();
                foreach ((array) $user->roles as $role) {
                    $values[] = (string) $role;
                    $values[] = $this->format_wp_role_label($role);
                }
                return $this->role_map_key_matches_values($key, $values);
            case 'group':
                return $this->role_map_key_matches_values($key, $this->user_taxonomy_match_values($user_id, 'eum_user_group', false));
            case 'job_path':
                $values = array_merge(
                    $this->user_taxonomy_match_values($user_id, 'eum_job_path', true),
                    $this->user_job_path_role_labels($user_id),
                    array($this->user_job_title_label($user_id))
                );
                return $this->role_map_key_matches_values($key, $values);
            case 'path_title':
                return $this->role_map_path_title_matches_user($key, $user_id);
            case 'project':
                return $this->role_map_key_matches_values($key, $this->user_taxonomy_match_values($user_id, 'eum_project', false));
            case 'location':
                $values = array_merge(
                    $this->user_taxonomy_match_values($user_id, 'eum_location', true),
                    array($this->user_tax_path_label($user_id, 'eum_location'))
                );
                return $this->role_map_key_matches_values($key, $values);
            case 'user':
                return $this->role_map_user_matches($key, $user);
            case 'event':
                $event = $this->parse_event_role_map_key($key);
                if (empty($event['event_id'])) {
                    return false;
                }
                return $this->event_role_map_matches_user($user_id, $event['event_id'], $event['status']);
        }
        return false;
    }

    protected function role_map_key_matches_values($key, $values) {
        $values = array_values(array_unique(array_filter(array_map('strval', (array) $values))));
        if (trim((string) $key) === '') {
            return !empty($values);
        }
        $needle = $this->normalize_role_map_key($key);
        foreach ($values as $value) {
            if ($this->normalize_role_map_key($value) === $needle) {
                return true;
            }
        }
        return false;
    }

    protected function role_map_path_title_matches_user($key, $user_id) {
        if (!$user_id || !taxonomy_exists('eum_job_path')) {
            return false;
        }
        $parsed = $this->parse_role_map_path_title_key($key);
        $path_id = isset($parsed['path_id']) ? (int) $parsed['path_id'] : 0;
        if ($path_id <= 0) {
            return false;
        }
        $user_path_ids = $this->user_taxonomy_term_ids($user_id, 'eum_job_path', false);
        if (!in_array($path_id, array_map('intval', $user_path_ids), true)) {
            return false;
        }
        $title_id = isset($parsed['title_id']) ? (int) $parsed['title_id'] : 0;
        if ($title_id > 0) {
            return $this->user_job_title_id($user_id) === $title_id;
        }
        $title_label = isset($parsed['title_label']) ? (string) $parsed['title_label'] : '';
        if ($title_label !== '') {
            return $this->role_map_key_matches_values($title_label, $this->user_job_title_match_values($user_id));
        }
        return false;
    }

    protected function parse_role_map_path_title_key($key) {
        $raw = html_entity_decode((string) $key, ENT_QUOTES, get_bloginfo('charset'));
        $clean = trim(str_replace(array('{', '}'), ' ', $raw));
        $clean = preg_replace('/\s+/', ' ', $clean);
        $path_id = 0;
        $title_id = 0;
        $title_label = '';

        if (preg_match('/(?:^|\b)(?:job\s+path|path)\s*[:=#]?\s*([0-9]+)/i', $clean, $matches)) {
            $path_id = absint($matches[1]);
        }
        if (preg_match('/(?:eum[_\s-]*job[_\s-]*title|job\s+title|title)\s*[:=#]?\s*([0-9]+)/i', $clean, $matches)) {
            $title_id = absint($matches[1]);
        }

        if ($path_id <= 0 || $title_id <= 0) {
            $compact = strtolower(preg_replace('/\s+/', '', str_replace(array('{', '}'), '', $raw)));
            if (preg_match('/^([0-9]+)(?:[:\/|>])(?:eum_job_title:)?([0-9]+)$/i', $compact, $matches)) {
                $path_id = $path_id > 0 ? $path_id : absint($matches[1]);
                $title_id = $title_id > 0 ? $title_id : absint($matches[2]);
            }
        }

        if ($path_id > 0 && $title_id <= 0) {
            $label_candidate = trim(preg_replace('/^(?:path\s*[:=#]?\s*)?[0-9]+\s*(?:[:\/|>]|\s+-\s*)\s*/i', '', $clean));
            $label_candidate = trim(preg_replace('/^(?:eum[_\s-]*job[_\s-]*title|job\s+title|title)\s*[:=#]?\s*/i', '', $label_candidate));
            if ($label_candidate !== '' && !ctype_digit($label_candidate)) {
                $title_label = sanitize_text_field($label_candidate);
            }
        }

        return array(
            'path_id' => $path_id,
            'title_id' => $title_id,
            'title_label' => $title_label,
        );
    }

    protected function normalize_role_map_key($value) {
        $value = html_entity_decode((string) $value, ENT_QUOTES, get_bloginfo('charset'));
        $value = strtolower(trim($value));
        $value = trim($value, "{} \t\n\r\0\x0B");
        $value = str_replace(array('->', '\\', '>'), '/', $value);
        $value = preg_replace('/\s*\/\s*/', '/', $value);
        $value = preg_replace('/[\s_-]+/', ' ', $value);
        return trim($value, " \t\n\r\0\x0B/");
    }

    protected function role_map_user_matches($key, $user) {
        $key = trim((string) $key);
        if ($key === '' || !$user) {
            return false;
        }
        $discord_id = (string) get_user_meta($user->ID, 'edb_discord_user_id', true);
        if (preg_match('/^(wp|wordpress|user|id|discord|email|login)\s*:\s*(.+)$/i', $key, $matches)) {
            $type = strtolower($matches[1]);
            $value = trim((string) $matches[2]);
            if (in_array($type, array('wp', 'wordpress', 'user', 'id'), true)) {
                return (string) $user->ID === $value;
            }
            if ($type === 'discord') {
                return $discord_id !== '' && $discord_id === $value;
            }
            if ($type === 'email') {
                return strcasecmp((string) $user->user_email, $value) === 0;
            }
            if ($type === 'login') {
                return strcasecmp((string) $user->user_login, $value) === 0;
            }
        }
        if (preg_match('/^[0-9]+$/', $key)) {
            return (string) $user->ID === $key || ($discord_id !== '' && $discord_id === $key);
        }
        return $this->role_map_key_matches_values($key, array($user->user_email, $user->user_login, $user->display_name));
    }

    protected function parse_event_role_map_key($key) {
        $key = str_replace(array('{', '}'), ' ', trim((string) $key));
        $key = preg_replace('/\s+/', ' ', $key);
        if (preg_match('/^([0-9]+)\s*[:\/,\-\s]\s*(.+)$/', $key, $matches)) {
            return array(
                'event_id' => absint($matches[1]),
                'status' => $this->normalize_event_rsvp_status($matches[2]),
            );
        }
        if (preg_match('/^event\s*=\s*([0-9]+)\s+(.+)$/i', $key, $matches)) {
            return array(
                'event_id' => absint($matches[1]),
                'status' => $this->normalize_event_rsvp_status($matches[2]),
            );
        }
        if (preg_match('/^([0-9]+)$/', $key, $matches)) {
            return array(
                'event_id' => absint($matches[1]),
                'status' => 'invited',
            );
        }
        return array('event_id' => 0, 'status' => '');
    }

    protected function normalize_event_rsvp_status($status) {
        $status = strtolower(trim((string) $status));
        $status = str_replace(array('_', '-'), ' ', $status);
        $status = preg_replace('/\s+/', ' ', $status);
        if ($status === '') {
            return '';
        }
        if (in_array($status, array('accept', 'accepted', 'acceped', 'yes', 'going'), true)) {
            return 'accept';
        }
        if (in_array($status, array('decline', 'declined', 'no'), true)) {
            return 'decline';
        }
        if (in_array($status, array('maybe', 'tentative'), true)) {
            return 'maybe';
        }
        if (in_array($status, array('responded', 'response'), true)) {
            return 'responded';
        }
        if (in_array($status, array('any invited', 'all invited'), true)) {
            return 'all_invited';
        }
        if (in_array($status, array('invited', 'pending', 'no response', 'noresponse'), true)) {
            return 'invited';
        }
        return 'invited';
    }

    protected function event_role_map_matches_user($user_id, $event_id, $status) {
        $event_id = absint($event_id);
        if ($event_id <= 0) {
            return false;
        }
        $status = $this->normalize_event_rsvp_status($status);
        $current = $this->event_user_rsvp_status($event_id, $user_id);
        $invited = $this->event_user_is_invited($event_id, $user_id);
        if ($status === 'accept') {
            return $current === 'accept';
        }
        if ($status === 'decline') {
            return $current === 'decline';
        }
        if ($status === 'maybe') {
            return $current === 'maybe';
        }
        if ($status === 'responded') {
            return in_array($current, array('accept', 'decline', 'maybe'), true);
        }
        if ($status === 'all_invited') {
            return $invited;
        }
        return $invited && $current === '';
    }

    protected function event_user_rsvp_status($event_id, $user_id) {
        $rsvps = get_post_meta($event_id, $this->event_meta_key('META_RSVPS', '_eum_event_rsvps'), true);
        if (!is_array($rsvps)) {
            return '';
        }
        $record = null;
        if (isset($rsvps[$user_id]) && is_array($rsvps[$user_id])) {
            $record = $rsvps[$user_id];
        } elseif (isset($rsvps[(string) $user_id]) && is_array($rsvps[(string) $user_id])) {
            $record = $rsvps[(string) $user_id];
        }
        if (!$record || empty($record['status'])) {
            return '';
        }
        return $this->normalize_event_rsvp_status($record['status']);
    }

    protected function event_user_is_invited($event_id, $user_id) {
        $user_id = absint($user_id);
        if ($user_id <= 0) {
            return false;
        }
        if (in_array($user_id, $this->event_meta_int_array($event_id, $this->event_meta_key('META_INVITED_USERS', '_eum_event_invited_users')), true)) {
            return true;
        }
        $tax_map = array(
            'eum_job_path' => $this->event_meta_key('META_INVITED_JOB_PATHS', '_eum_event_invited_job_paths'),
            'eum_user_group' => $this->event_meta_key('META_INVITED_GROUPS', '_eum_event_invited_groups'),
            'eum_project' => $this->event_meta_key('META_INVITED_PROJECTS', '_eum_event_invited_projects'),
            'eum_location' => $this->event_meta_key('META_INVITED_LOCATIONS', '_eum_event_invited_locations'),
        );
        foreach ($tax_map as $taxonomy => $meta_key) {
            $invited_terms = $this->event_meta_int_array($event_id, $meta_key);
            if (empty($invited_terms)) {
                continue;
            }
            $user_terms = $this->user_taxonomy_term_ids($user_id, $taxonomy, in_array($taxonomy, array('eum_job_path', 'eum_location'), true));
            if (!empty(array_intersect($invited_terms, $user_terms))) {
                return true;
            }
        }
        return false;
    }

    protected function event_meta_key($constant_name, $fallback) {
        $constant = 'EUM_Events::' . $constant_name;
        if (class_exists('EUM_Events') && defined($constant)) {
            return constant($constant);
        }
        return $fallback;
    }

    protected function event_meta_int_array($event_id, $meta_key) {
        $value = get_post_meta((int) $event_id, $meta_key, true);
        return array_values(array_filter(array_map('intval', (array) $value)));
    }

    protected function find_guild_role_id($guild_id, $role_name) {
        $role_name = trim((string) $role_name);
        if ($role_name === '') {
            return '';
        }
        $candidates = $this->role_name_lookup_candidates($role_name);
        $roles = $this->get_guild_roles($guild_id);
        foreach ($roles as $role) {
            if (isset($role['name']) && in_array((string) $role['name'], $candidates, true)) {
                return (string) $role['id'];
            }
        }
        return '';
    }

    protected function role_name_lookup_candidates($role_name) {
        $role_name = trim((string) $role_name);
        $candidates = array($role_name);
        if (preg_match('/^(.+?:)\s*([0-9]+)$/', $role_name, $matches)) {
            $prefix = rtrim((string) $matches[1]);
            $id = (string) $matches[2];
            $candidates[] = $prefix . ' {' . $id . '}';
            $candidates[] = $prefix . '{' . $id . '}';
        }
        return array_values(array_unique(array_filter($candidates)));
    }

    protected function ensure_guild_role($guild_id, $role_name) {
        $existing = $this->find_guild_role_id($guild_id, $role_name);
        if ($existing !== '') {
            return $existing;
        }
        $created = $this->discord_api('POST', '/guilds/' . $guild_id . '/roles', array(
            'name' => $role_name,
            'mentionable' => false,
            'hoist' => false,
        ), true);
        if (!empty($created['body']['id'])) {
            unset($this->role_cache[$guild_id]);
            return (string) $created['body']['id'];
        }
        return '';
    }

    protected function get_guild_roles($guild_id) {
        if (isset($this->role_cache[$guild_id])) {
            return $this->role_cache[$guild_id];
        }
        $resp = $this->discord_api('GET', '/guilds/' . $guild_id . '/roles', null, true);
        $roles = is_array($resp['body']) ? $resp['body'] : array();
        $this->role_cache[$guild_id] = $roles;
        return $roles;
    }

    protected function get_guild_member($guild_id, $discord_id) {
        $resp = $this->discord_api('GET', '/guilds/' . $guild_id . '/members/' . $discord_id, null, true);
        return ($resp['code'] === 200 && is_array($resp['body'])) ? $resp['body'] : array();
    }

    protected function get_guild_members($guild_id, $page_limit = 20) {
        $members = array();
        $after = '0';
        $loops = 0;
        do {
            $resp = $this->discord_api('GET', '/guilds/' . $guild_id . '/members?limit=1000&after=' . rawurlencode($after), null, true);
            if ((int) $resp['code'] !== 200 || !is_array($resp['body'])) {
                return $members;
            }
            $page = $resp['body'];
            $last_after = $after;
            foreach ($page as $member) {
                if (!is_array($member)) {
                    continue;
                }
                $user = isset($member['user']) && is_array($member['user']) ? $member['user'] : array();
                if (!empty($user['id'])) {
                    $last_after = (string) $user['id'];
                }
                $members[] = $member;
            }
            if ($last_after === $after) {
                break;
            }
            $after = $last_after;
            $loops++;
        } while (count($page) === 1000 && $loops < (int) $page_limit);
        return $members;
    }

    protected function sync_primary_guild_community_roles() {
        $settings = $this->settings();
        $guild_id = isset($settings['guild_id']) ? (string) $settings['guild_id'] : '';
        $role_name = isset($settings['primary_role_community']) ? trim((string) $settings['primary_role_community']) : 'Community';
        if ($guild_id === '' || $role_name === '' || empty($settings['auto_create_primary_roles'])) {
            return 0;
        }
        $community_role_id = $this->ensure_guild_role($guild_id, $role_name);
        if ($community_role_id === '') {
            return 0;
        }
        $primary_rules = json_decode(isset($settings['role_rules_json']) ? $settings['role_rules_json'] : '[]', true);
        $primary_rules = is_array($primary_rules) ? $primary_rules : array();
        $managed_pool = array_values(array_unique(array_filter(array_merge(
            $this->primary_managed_role_ids($guild_id, $settings),
            $this->primary_role_map_role_ids(),
            $this->rule_role_ids($primary_rules)
        ))));

        $linked_discord_ids = array();
        foreach ($this->get_linked_users() as $user) {
            $discord_id = (string) get_user_meta($user->ID, 'edb_discord_user_id', true);
            if ($discord_id !== '') {
                $linked_discord_ids[$discord_id] = true;
            }
        }

        $changed = 0;
        foreach ($this->get_guild_members($guild_id) as $member) {
            $discord_user = isset($member['user']) && is_array($member['user']) ? $member['user'] : array();
            $discord_id = isset($discord_user['id']) ? (string) $discord_user['id'] : '';
            if ($discord_id === '' || !empty($discord_user['bot'])) {
                continue;
            }
            $roles = isset($member['roles']) && is_array($member['roles']) ? array_map('strval', $member['roles']) : array();
            $has_role = in_array($community_role_id, $roles, true);
            $should_have_role = empty($linked_discord_ids[$discord_id]);
            if ($should_have_role) {
                $current_portal_roles = array_values(array_intersect($roles, $managed_pool));
                $expected_portal_roles = array($community_role_id);
                sort($current_portal_roles);
                sort($expected_portal_roles);
                if ($current_portal_roles !== $expected_portal_roles && $this->apply_roles_to_member_snapshot($guild_id, $discord_id, $roles, $expected_portal_roles, $managed_pool)) {
                    $changed++;
                }
            } elseif (!$should_have_role && $has_role && $this->remove_member_role($guild_id, $discord_id, $community_role_id)) {
                $changed++;
            }
        }
        if ($changed > 0) {
            delete_transient('edb_main_guild_members_' . md5($guild_id));
        }
        return $changed;
    }

    protected function add_member_role($guild_id, $discord_id, $role_id) {
        $resp = $this->discord_api('PUT', '/guilds/' . $guild_id . '/members/' . $discord_id . '/roles/' . $role_id, null, true);
        return in_array((int) $resp['code'], array(200, 201, 204), true);
    }

    protected function remove_member_role($guild_id, $discord_id, $role_id) {
        $resp = $this->discord_api('DELETE', '/guilds/' . $guild_id . '/members/' . $discord_id . '/roles/' . $role_id, null, true);
        return in_array((int) $resp['code'], array(200, 204), true);
    }

    protected function apply_roles_to_member_snapshot($guild_id, $discord_id, $current_roles, $desired_managed_roles, $managed_pool) {
        $current = array_values(array_unique(array_filter(array_map('strval', (array) $current_roles))));
        $managed_pool = array_values(array_unique(array_filter(array_map('strval', (array) $managed_pool))));
        $desired_managed_roles = array_values(array_unique(array_filter(array_map('strval', (array) $desired_managed_roles))));

        $to_remove = array_values(array_diff(array_intersect($current, $managed_pool), $desired_managed_roles));
        $to_add = array_values(array_diff($desired_managed_roles, $current));
        if (empty($to_remove) && empty($to_add)) {
            return true;
        }

        $ok = true;
        foreach ($to_remove as $role_id) {
            if (!$this->remove_member_role($guild_id, $discord_id, $role_id)) {
                $ok = false;
            }
        }
        foreach ($to_add as $role_id) {
            if (!$this->add_member_role($guild_id, $discord_id, $role_id)) {
                $ok = false;
            }
        }
        return $ok;
    }

    protected function apply_roles_to_member($guild_id, $discord_id, $desired_managed_roles, $managed_pool) {
        $member = $this->get_guild_member($guild_id, $discord_id);
        if (!isset($member['roles']) || !is_array($member['roles'])) {
            return false;
        }
        return $this->apply_roles_to_member_snapshot($guild_id, $discord_id, $member['roles'], $desired_managed_roles, $managed_pool);
    }

    protected function user_is_currently_clocked_in($user_id) {
        if (!class_exists('EUM_Timeclock')) {
            return false;
        }
        return EUM_Timeclock::user_is_clocked_in((int) $user_id);
    }

    protected function user_is_scheduled_now($user_id) {
        if (!class_exists('EUM_Scheduling')) {
            return false;
        }
        $now = gmdate('Y-m-d H:i:s');
        $q = new WP_Query(array(
            'post_type' => EUM_Scheduling::CPT_SHIFT,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => array(
                'relation' => 'AND',
                array('key' => EUM_Scheduling::META_USER_ID, 'value' => $user_id, 'compare' => '='),
                array('key' => EUM_Scheduling::META_SHIFT_START_UTC, 'value' => $now, 'compare' => '<='),
                array('key' => EUM_Scheduling::META_SHIFT_END_UTC, 'value' => $now, 'compare' => '>='),
            ),
        ));
        return !empty($q->posts);
    }

    protected function user_term_names($user_id, $taxonomy) {
        if (!taxonomy_exists($taxonomy)) {
            return array();
        }
        $terms = wp_get_object_terms($user_id, $taxonomy, array('fields' => 'names'));
        return is_array($terms) ? array_values(array_filter(array_map('strval', $terms))) : array();
    }

    protected function user_taxonomy_id_role_names($user_id, $taxonomy, $prefix, $include_ancestors = false) {
        $prefix = (string) $prefix;
        $names = array();
        foreach ($this->user_taxonomy_term_ids($user_id, $taxonomy, $include_ancestors) as $term_id) {
            if ($taxonomy === 'eum_user_group') {
                $term = get_term((int) $term_id, $taxonomy);
                if ($term && !is_wp_error($term) && in_array((string) $term->slug, array('discord-connected', 'discord-not-connected'), true)) {
                    $names[] = (string) $term->name;
                    continue;
                }
            }
            if ($prefix !== '') {
                $names[] = $prefix . (int) $term_id;
            }
        }
        return array_values(array_unique(array_filter($names)));
    }

    protected function user_taxonomy_term_ids($user_id, $taxonomy, $include_ancestors = false) {
        if (!$user_id || !taxonomy_exists($taxonomy)) {
            return array();
        }
        $ids = array();
        if (class_exists('EUM_Profiles') && method_exists('EUM_Profiles', 'get_field_raw_value')) {
            $raw = EUM_Profiles::get_field_raw_value((int) $user_id, 'tax:' . $taxonomy);
            if (is_array($raw)) {
                $ids = array_merge($ids, array_map('intval', $raw));
            } elseif ((int) $raw > 0) {
                $ids[] = (int) $raw;
            }
        }
        $term_ids = wp_get_object_terms($user_id, $taxonomy, array('fields' => 'ids'));
        if (is_array($term_ids) && !is_wp_error($term_ids)) {
            $ids = array_merge($ids, array_map('intval', $term_ids));
        }
        $meta_value = get_user_meta($user_id, 'tax:' . $taxonomy, true);
        if (is_array($meta_value)) {
            $ids = array_merge($ids, array_map('intval', $meta_value));
        } elseif ((int) $meta_value > 0) {
            $ids[] = (int) $meta_value;
        }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($include_ancestors) {
            foreach ($ids as $term_id) {
                $ids = array_merge($ids, array_map('intval', (array) get_ancestors($term_id, $taxonomy)));
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }

    protected function user_taxonomy_match_values($user_id, $taxonomy, $include_ancestors = false) {
        $values = array();
        foreach ($this->user_taxonomy_term_ids($user_id, $taxonomy, $include_ancestors) as $term_id) {
            $values = array_merge($values, $this->term_match_values($term_id, $taxonomy));
        }
        return array_values(array_unique(array_filter(array_map('strval', $values))));
    }

    protected function term_match_values($term_id, $taxonomy) {
        $term = get_term((int) $term_id, $taxonomy);
        if (!$term || is_wp_error($term)) {
            return array();
        }
        $values = array(
            (string) $term->term_id,
            (string) $term->slug,
            (string) $term->name,
        );
        $path = $this->term_full_path_label($taxonomy, (int) $term->term_id);
        if ($path !== '') {
            $values[] = $path;
        }
        return $values;
    }

    protected function term_full_path_label($taxonomy, $term_id, $separator = ' / ') {
        if (class_exists('EUM_Profiles') && method_exists('EUM_Profiles', 'term_full_path')) {
            $path = (string) EUM_Profiles::term_full_path($taxonomy, (int) $term_id, $separator);
            if ($path !== '') {
                return sanitize_text_field($path);
            }
        }
        $term = get_term((int) $term_id, $taxonomy);
        if (!$term || is_wp_error($term)) {
            return '';
        }
        $names = array(sanitize_text_field((string) $term->name));
        $parent = isset($term->parent) ? (int) $term->parent : 0;
        while ($parent > 0) {
            $parent_term = get_term($parent, $taxonomy);
            if (!$parent_term || is_wp_error($parent_term)) {
                break;
            }
            array_unshift($names, sanitize_text_field((string) $parent_term->name));
            $parent = (int) $parent_term->parent;
        }
        return implode($separator, array_filter($names));
    }

    protected function user_job_path_label($user_id) {
        $labels = $this->user_job_path_role_labels($user_id);
        return !empty($labels) ? end($labels) : '';
    }

    protected function user_job_path_role_labels($user_id) {
        $segments = $this->user_job_path_segments($user_id);
        $labels = array();
        $parts = array();
        foreach ($segments as $segment) {
            $segment = trim((string) $segment);
            if ($segment === '') {
                continue;
            }
            $parts[] = $segment;
            $labels[] = implode(' / ', $parts);
        }
        return array_values(array_unique(array_filter($labels)));
    }

    protected function user_job_path_segments($user_id) {
        $segments = array();
        if ($user_id && taxonomy_exists('eum_job_path')) {
            $term_ids = wp_get_object_terms($user_id, 'eum_job_path', array('fields' => 'ids'));
            if (!empty($term_ids)) {
                $term_id = (int) $term_ids[0];
                $current = get_term($term_id, 'eum_job_path');
                $stack = array();
                while ($current && !is_wp_error($current)) {
                    array_unshift($stack, sanitize_text_field((string) $current->name));
                    if (empty($current->parent)) {
                        break;
                    }
                    $current = get_term((int) $current->parent, 'eum_job_path');
                }
                $segments = $stack;
            }
        }
        if (empty($segments)) {
            $fallback = (string) get_user_meta($user_id, 'job_path', true);
            if ($fallback !== '') {
                foreach (preg_split('/\s*\/\s*/', str_replace(' > ', '/', $fallback)) as $part) {
                    $part = trim((string) $part);
                    if ($part !== '') {
                        $segments[] = $part;
                    }
                }
            }
        }
        $job_title = $this->user_job_title_label($user_id);
        if ($job_title !== '') {
            $last = !empty($segments) ? end($segments) : '';
            if (strcasecmp((string) $last, $job_title) !== 0) {
                $segments[] = $job_title;
            }
        }
        return array_values(array_filter(array_map('sanitize_text_field', $segments)));

    }

    protected function user_job_title_label($user_id) {
        if (!$user_id) {
            return '';
        }
        if (class_exists('EUM_Profiles') && method_exists('EUM_Profiles', 'preferred_job_title_label')) {
            $title = (string) EUM_Profiles::preferred_job_title_label($user_id);
            if ($title !== '') {
                return sanitize_text_field($title);
            }
        }
        if (class_exists('EUM_Profiles') && method_exists('EUM_Profiles', 'get_field_raw_value')) {
            $raw = EUM_Profiles::get_field_raw_value($user_id, 'custom:job_title');
            if (is_string($raw) && $raw !== '') {
                return sanitize_text_field($raw);
            }
        }
        return sanitize_text_field((string) get_user_meta($user_id, 'job_title', true));
    }

    protected function user_job_title_id($user_id) {
        if (!$user_id) {
            return 0;
        }
        $raw_values = array();
        if (class_exists('EUM_Profiles') && method_exists('EUM_Profiles', 'get_field_raw_value')) {
            $raw_values[] = EUM_Profiles::get_field_raw_value((int) $user_id, 'custom:job_title');
        }
        $raw_values[] = get_user_meta($user_id, 'job_title', true);
        foreach ($raw_values as $raw) {
            if (is_array($raw)) {
                continue;
            }
            $raw = trim((string) $raw);
            if ($raw === '') {
                continue;
            }
            if (class_exists('EUM_Profiles') && method_exists('EUM_Profiles', 'job_title_post_id_from_value')) {
                $title_id = (int) EUM_Profiles::job_title_post_id_from_value($raw);
                if ($title_id > 0) {
                    return $title_id;
                }
            }
            if (class_exists('EUM_Job_Titles') && method_exists('EUM_Job_Titles', 'id_from_token')) {
                $title_id = (int) EUM_Job_Titles::id_from_token($raw);
                if ($title_id > 0) {
                    return $title_id;
                }
            }
            if (ctype_digit($raw)) {
                return (int) $raw;
            }
        }
        return 0;
    }

    protected function user_job_title_match_values($user_id) {
        $values = array();
        $title_id = $this->user_job_title_id($user_id);
        if ($title_id > 0) {
            $values[] = (string) $title_id;
            $values[] = 'eum_job_title:' . $title_id;
            if (class_exists('EUM_Job_Titles') && method_exists('EUM_Job_Titles', 'title_label')) {
                $label = (string) EUM_Job_Titles::title_label($title_id);
                if ($label !== '') {
                    $values[] = $label;
                }
            }
        }
        $label = $this->user_job_title_label($user_id);
        if ($label !== '') {
            $values[] = $label;
        }
        return array_values(array_unique(array_filter(array_map('strval', $values))));
    }

    protected function user_employment_status($user_id) {
        if (class_exists('EUM_Personnel') && method_exists('EUM_Personnel', 'is_terminated')) {
            return EUM_Personnel::is_terminated((int) $user_id) ? 'terminated' : 'active';
        }
        $eum_terminated = get_user_meta($user_id, 'eum_terminated', true);
        if ($eum_terminated && (string) $eum_terminated !== '0') {
            return 'terminated';
        }
        $terminated_meta = get_user_meta($user_id, 'system:eum_terminated', true);
        if ($terminated_meta && (string) $terminated_meta !== '0') {
            return 'terminated';
        }
        $status = sanitize_key((string) get_user_meta($user_id, 'status', true));
        if (strpos($status, 'term') !== false) {
            return 'terminated';
        }
        if ($status !== '') {
            return $status;
        }
        return 'active';
    }

    protected function guild_role_name_map($guild_id) {
        $map = array();
        if (!$guild_id) {
            return $map;
        }
        foreach ($this->get_guild_roles($guild_id) as $role) {
            if (!empty($role['id']) && isset($role['name'])) {
                $map[(string) $role['id']] = (string) $role['name'];
            }
        }
        return $map;
    }

    protected function inspect_rule_set($rules, $user_id, $guild_id = '') {
        $rows = array();
        $user = get_userdata($user_id);
        if (!$user) {
            return $rows;
        }
        $user_groups = $this->user_term_names($user_id, 'eum_user_group');
        $projects = $this->user_term_names($user_id, 'eum_project');
        $job_path = $this->user_job_path_label($user_id);
        $terminated = (string) get_user_meta($user_id, 'status', true) . ' ' . (string) get_user_meta($user_id, 'system:eum_terminated', true) . ' ' . (string) get_user_meta($user_id, 'eum_terminated', true);
        $role_map = $this->guild_role_name_map($guild_id);
        foreach ((array) $rules as $index => $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $inspection = $this->inspect_rule_match($rule, $user_id, $user, $user_groups, $projects, $job_path, $terminated);
            $role_id = isset($rule['role_id']) ? (string) $rule['role_id'] : '';
            $rows[] = array(
                'index' => $index + 1,
                'matched' => !empty($inspection['matched']),
                'reasons' => isset($inspection['reasons']) ? (array) $inspection['reasons'] : array(),
                'role_id' => $role_id,
                'role_name' => $role_id && isset($role_map[$role_id]) ? $role_map[$role_id] : '',
                'rule' => $rule,
            );
        }
        return $rows;
    }

    protected function inspect_primary_role_maps($user_id, $guild_id = '') {
        $role_map = $this->guild_role_name_map($guild_id);
        $rows = array();
        foreach ($this->primary_role_map_rows() as $index => $row) {
            $role_id = isset($row['role_id']) ? (string) $row['role_id'] : '';
            $rows[] = array(
                'index' => $index + 1,
                'matched' => $this->primary_role_map_row_matches_user($row, $user_id),
                'category' => isset($row['category']) ? (string) $row['category'] : '',
                'key' => isset($row['key']) ? (string) $row['key'] : '',
                'role_id' => $role_id,
                'role_name' => $role_id !== '' && isset($role_map[$role_id]) ? $role_map[$role_id] : '',
                'source' => isset($row['source']) ? (string) $row['source'] : '',
            );
        }
        return $rows;
    }

    protected function build_role_tester_report($user_id, $target_guild_id = '') {
        $user = get_userdata($user_id);
        if (!$user) {
            return array('error' => 'The selected user could not be found.');
        }
        $settings = $this->settings();
        $resolved_target_guild_id = $target_guild_id !== '' ? (string) $target_guild_id : (string) $settings['guild_id'];
        $discord_id = (string) get_user_meta($user_id, 'edb_discord_user_id', true);
        $cached_guilds = $this->get_cached_guilds($user_id);
        $cached_target = false;
        foreach ($cached_guilds as $guild) {
            if (!empty($guild['id']) && (string) $guild['id'] === $resolved_target_guild_id) {
                $cached_target = true;
                break;
            }
        }
        $member_in_target = false;
        if ($discord_id !== '' && $resolved_target_guild_id !== '') {
            $member_in_target = !empty($this->get_guild_member($resolved_target_guild_id, $discord_id));
        }

        $primary_auto = $this->desired_primary_guild_roles($user_id);
        $primary_rules = json_decode($settings['role_rules_json'], true);
        $secondary_rules = json_decode($settings['secondary_guild_rules_json'], true);
        $secondary_by_guild = array();
        if (is_array($secondary_rules)) {
            foreach ($secondary_rules as $rule) {
                if (!is_array($rule) || empty($rule['guild_id'])) {
                    continue;
                }
                $gid = (string) $rule['guild_id'];
                if ($resolved_target_guild_id !== '' && $gid !== $resolved_target_guild_id) {
                    continue;
                }
                $secondary_by_guild[$gid][] = $rule;
            }
        }
        $known_guilds = $this->known_guild_options();
        $secondary_reports = array();
        foreach ($secondary_by_guild as $guild_id => $rules) {
            $rows = $this->inspect_rule_set($rules, $user_id, $guild_id);
            $matched_role_names = array();
            foreach ($rows as $row) {
                if (!empty($row['matched'])) {
                    $matched_role_names[] = $row['role_name'] !== '' ? $row['role_name'] : ('Role ' . $row['role_id']);
                }
            }
            $secondary_reports[] = array(
                'guild_id' => $guild_id,
                'guild_label' => isset($known_guilds[$guild_id]) ? $known_guilds[$guild_id] : ('Guild ' . $guild_id),
                'matched_role_names' => array_values(array_unique(array_filter($matched_role_names))),
                'rules' => $rows,
            );
        }

        return array(
            'generated_at' => current_time('mysql'),
            'user' => array(
                'id' => (int) $user_id,
                'name' => $user->display_name,
                'email' => $user->user_email,
            ),
            'discord' => array(
                'linked' => $discord_id !== '',
                'discord_id' => $discord_id,
                'label' => $this->discord_label_for_user($user_id),
                'cached_guilds' => $cached_guilds,
                'cached_in_target' => $cached_target,
                'member_in_target' => $member_in_target,
            ),
            'meta' => array(
                'eum_terminated' => (string) get_user_meta($user_id, 'eum_terminated', true),
                'system_eum_terminated' => (string) get_user_meta($user_id, 'system:eum_terminated', true),
                'status' => (string) get_user_meta($user_id, 'status', true),
            ),
            'employment_status' => $this->user_employment_status($user_id),
            'conditions' => array(
                'currently_clocked_in' => $this->user_is_currently_clocked_in($user_id),
                'scheduled_now' => $this->user_is_scheduled_now($user_id),
            ),
            'target_guild_id' => $resolved_target_guild_id,
            'primary' => array(
                'guild_id' => (string) $settings['guild_id'],
                'auto_roles' => isset($primary_auto['labels']) ? (array) $primary_auto['labels'] : array(),
                'term_ids' => array(
                    'Path' => $this->user_taxonomy_term_ids($user_id, 'eum_job_path', true),
                    'Group' => $this->user_taxonomy_term_ids($user_id, 'eum_user_group', false),
                    'Project' => $this->user_taxonomy_term_ids($user_id, 'eum_project', false),
                ),
                'easy_maps' => $this->inspect_primary_role_maps($user_id, (string) $settings['guild_id']),
                'rules' => $this->inspect_rule_set(is_array($primary_rules) ? $primary_rules : array(), $user_id, (string) $settings['guild_id']),
            ),
            'secondary' => $secondary_reports,
        );
    }

    protected function known_guild_options() {
        $settings = $this->settings();
        $guilds = array();
        if (!empty($settings['guild_id'])) {
            $guilds[(string) $settings['guild_id']] = 'Primary Guild (' . (string) $settings['guild_id'] . ')';
        }
        foreach ($this->get_linked_users() as $user) {
            foreach ($this->get_cached_guilds($user->ID) as $guild) {
                if (empty($guild['id'])) {
                    continue;
                }
                $guilds[(string) $guild['id']] = trim(((string) ($guild['name'] ?? 'Guild')) . ' (' . (string) $guild['id'] . ')');
            }
        }
        asort($guilds);
        return $guilds;
    }

    protected function resolve_target_guild_id($selected_guild_id, $custom_guild_id) {
        $custom_guild_id = sanitize_text_field((string) $custom_guild_id);
        if ($custom_guild_id !== '') {
            return $custom_guild_id;
        }
        return sanitize_text_field((string) $selected_guild_id);
    }

    protected function discord_label_for_user($user_id) {
        $global = (string) get_user_meta($user_id, 'edb_discord_global_name', true);
        $username = (string) get_user_meta($user_id, 'edb_discord_username', true);
        $discord_id = (string) get_user_meta($user_id, 'edb_discord_user_id', true);
        $label = $global !== '' ? $global : $username;
        if ($label === '') {
            return $discord_id ? 'Discord #' . $discord_id : 'No linked Discord account';
        }
        return $discord_id ? $label . ' (' . $discord_id . ')' : $label;
    }

    protected function password_reset_landing_url() {
        $settings = $this->settings();
        return !empty($settings['password_reset_page_url']) ? $settings['password_reset_page_url'] : home_url('/?edb_discord_password_reset=1');
    }

    public function allow_public_password_reset_request($allowed) {
        if ($allowed) {
            return true;
        }

        $oauth_action = isset($_GET['edb_discord_oauth']) ? sanitize_key(wp_unslash($_GET['edb_discord_oauth'])) : '';
        if (in_array($oauth_action, array('start', 'callback'), true)) {
            return true;
        }

        if (!empty($_GET['edb_discord_password_reset'])) {
            return true;
        }

        $settings = $this->settings();
        $configured_url = isset($settings['password_reset_page_url']) ? (string) $settings['password_reset_page_url'] : '';
        if ($configured_url !== '' && $this->current_request_matches_url($configured_url)) {
            return true;
        }

        if (is_page()) {
            $post = get_queried_object();
            if ($post instanceof WP_Post && has_shortcode((string) $post->post_content, 'eum_discord_password_reset')) {
                return true;
            }
        }

        return false;
    }

    public function handle_password_reset_landing() {
        if (empty($_GET['edb_discord_password_reset'])) {
            return;
        }
        status_header(200);
        nocache_headers();
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>Reset Password with Discord</title>';
        echo '<link rel="stylesheet" href="' . esc_url(EDB_PLUGIN_URL . 'assets/edb-ui.css') . '">';
        echo '</head><body class="edb-reset-body">';
        echo '<div class="edb-reset-shell">' . $this->render_password_reset_panel() . '</div>';
        echo '<script src="' . esc_url(EDB_PLUGIN_URL . 'assets/edb-ui.js') . '"></script>';
        echo '</body></html>';
        exit;
    }

    protected function render_password_reset_panel() {
        $error = isset($_GET['edb_reset_error']) ? sanitize_key(wp_unslash($_GET['edb_reset_error'])) : '';
        $error_map = array(
            'not-linked' => 'That Discord account is not linked to a WordPress profile yet.',
            'user-missing' => 'The linked WordPress user could not be found.',
        );
        ob_start();
        echo '<div class="edb-card edb-card--reset"><div class="edb-card__head"><div><h2>Reset Password with Discord</h2><p>Authenticate with your linked Discord account and we will send you directly into WordPress password reset.</p></div></div>';
        if ($error && isset($error_map[$error])) {
            echo '<div class="edb-callout edb-callout--error">' . esc_html($error_map[$error]) . '</div>';
        }
        echo '<p><a class="button button-primary button-hero" href="' . esc_url($this->oauth_url('reset', 0)) . '">Continue with Discord</a></p>';
        echo '<p class="description">This reset flow only works for Discord accounts already linked to a portal user.</p></div>';
        return ob_get_clean();
    }

    protected function current_request_matches_url($url) {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if ($request_uri === '' || $url === '') {
            return false;
        }

        $current = wp_parse_url(home_url($request_uri));
        $target = wp_parse_url($url);
        if (!is_array($current) || !is_array($target)) {
            return false;
        }

        $current_path = trim((string) ($current['path'] ?? ''), '/');
        $target_path = trim((string) ($target['path'] ?? ''), '/');
        if ($current_path === '' || $target_path === '' || $current_path !== $target_path) {
            return false;
        }

        $target_query = array();
        parse_str((string) ($target['query'] ?? ''), $target_query);
        if (empty($target_query)) {
            return true;
        }

        $current_query = array();
        parse_str((string) ($current['query'] ?? ''), $current_query);
        foreach ($target_query as $key => $value) {
            if (!array_key_exists($key, $current_query) || (string) $current_query[$key] !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    public function register_rest_routes() {
        register_rest_route('edb/v1', '/password-reset-command', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_password_reset_command'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('edb/v1', '/role-sync', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_role_sync'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('edb/v1', '/restore-member-command', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_restore_member_command'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('edb/v1', '/discord-permissions-command', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_discord_permissions_command'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('edb/v1', '/guilds-refresh', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_guild_refresh'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('edb/v1', '/automation-pass', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_automation_pass'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('edb/v1', '/timeclock-command', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_timeclock_command'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('edb/v1', '/profile-command', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_profile_command'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('edb/v1', '/attention-command', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_attention_command'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('edb/v1', '/leave-command', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_leave_command'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('edb/v1', '/availability-command', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_availability_command'),
            'permission_callback' => '__return_true',
        ));
    }

    public function rest_password_reset_command($request) {
        $secret = $request->get_header('x-edb-secret');
        if (!$secret || $secret !== get_option(self::RESET_SECRET_OPTION)) {
            return new WP_REST_Response(array('ok' => false, 'message' => 'Unauthorized'), 401);
        }
        $discord_user_id = sanitize_text_field($request->get_param('discord_user_id'));
        $user_id = $this->find_user_by_discord_id_only($discord_user_id);
        if (!$user_id) {
            return new WP_REST_Response(array('ok' => false, 'message' => 'No linked user found'), 404);
        }
        $user = get_userdata($user_id);
        return new WP_REST_Response(array(
            'ok' => true,
            'user_id' => $user_id,
            'display_name' => $user ? $user->display_name : '',
            'start_url' => $this->password_reset_landing_url(),
        ));
    }

    public function rest_role_sync($request) {
        $secret = $request->get_header('x-edb-secret');
        if (!$secret || $secret !== get_option(self::RESET_SECRET_OPTION)) {
            return new WP_REST_Response(array('ok' => false, 'message' => 'Unauthorized'), 401);
        }
        $user_ids = $request->get_param('user_ids');
        $user_ids = is_array($user_ids) ? array_values(array_filter(array_map('absint', $user_ids))) : array();
        $discord_user_id = sanitize_text_field((string) $request->get_param('discord_user_id'));
        $synced_user_ids = array();
        $failed_user_ids = array();
        $scope = 'all';

        if (!empty($user_ids)) {
            $scope = 'selected';
            foreach ($user_ids as $user_id) {
                if ($this->safe_run_single_user_sync($user_id)) {
                    $synced_user_ids[] = $user_id;
                } else {
                    $failed_user_ids[] = $user_id;
                }
            }
        } elseif ($discord_user_id !== '') {
            $scope = 'self';
            $actor_user_id = $this->find_user_by_discord_id_only($discord_user_id);
            if (!$actor_user_id) {
                return new WP_REST_Response(array('ok' => false, 'message' => 'No linked portal user found'), 404);
            }
            if ($this->safe_run_single_user_sync($actor_user_id)) {
                $synced_user_ids[] = $actor_user_id;
            } else {
                return new WP_REST_Response(array('ok' => false, 'message' => 'Role sync could not be completed for your linked portal account.'), 500);
            }
        } else {
            $this->run_dynamic_role_sync();
        }

        $this->log_configured_activity_message($this->format_activity_message('bot_role_sync', array(
            'summary' => 'Discord bot requested a role sync.',
            'details' => array(
                'scope' => $scope,
                'user_count' => count($user_ids),
                'synced_user_ids' => $synced_user_ids,
                'failed_user_ids' => $failed_user_ids,
            ),
        )));
        if (!empty($failed_user_ids)) {
            return new WP_REST_Response(array(
                'ok' => false,
                'message' => 'Role sync failed for one or more requested users.',
                'scope' => $scope,
                'synced_user_ids' => $synced_user_ids,
                'failed_user_ids' => $failed_user_ids,
            ), 500);
        }
        return new WP_REST_Response(array(
            'ok' => true,
            'message' => $scope === 'self'
                ? 'Your linked account roles were synced.'
                : ($scope === 'selected' ? 'Requested account roles were synced.' : 'All linked account roles were synced.'),
            'scope' => $scope,
            'synced_user_ids' => $synced_user_ids,
            'failed_user_ids' => $failed_user_ids,
        ));
    }

    public function rest_restore_member_command($request) {
        $secret = $request->get_header('x-edb-secret');
        if (!$secret || !hash_equals((string)get_option(self::RESET_SECRET_OPTION), (string)$secret)) {
            return new WP_REST_Response(array('ok' => false, 'message' => 'Unauthorized'), 401);
        }
        $actor_discord_id = sanitize_text_field((string)$request->get_param('discord_user_id'));
        $actor_user_id = $this->find_user_by_discord_id_only($actor_discord_id);
        if (!$actor_user_id) return new WP_REST_Response(array('ok' => false, 'message' => 'Your Discord account is not linked to a portal user.'), 404);

        $target_discord_id = sanitize_text_field((string)$request->get_param('target_discord_user_id'));
        $target_reference = sanitize_text_field((string)$request->get_param('target'));
        if ($target_discord_id !== '') {
            $target_user_id = $this->find_user_by_discord_id_only($target_discord_id);
        } elseif ($target_reference !== '') {
            $target_user_id = (int)$this->resolve_portal_user_reference($target_reference, '');
        } else {
            $target_user_id = (int)$actor_user_id;
        }
        if (!$target_user_id || !get_userdata($target_user_id)) {
            return new WP_REST_Response(array('ok' => false, 'message' => 'No linked portal account was found for that Discord user.'), 404);
        }
        if ((int)$actor_user_id !== (int)$target_user_id && !$this->can_restore_discord_member_for($actor_user_id, $target_user_id)) {
            return new WP_REST_Response(array('ok' => false, 'message' => 'You can only restore members in your management scope.'), 403);
        }
        if ($this->user_employment_status($target_user_id) === 'terminated'
            && !user_can($actor_user_id, 'manage_options')
            && !(class_exists('EUM_Access') && EUM_Access::is_it_or_hr((int)$actor_user_id))) {
            return new WP_REST_Response(array('ok' => false, 'message' => 'Only HR or IT can manually restore a terminated user to Discord.'), 403);
        }
        $discord_id = trim((string)get_user_meta($target_user_id, 'edb_discord_user_id', true));
        if ($discord_id === '') return new WP_REST_Response(array('ok' => false, 'message' => 'That portal account is not linked to Discord.'), 404);
        $settings = $this->settings();
        if (empty($settings['guild_id'])) return new WP_REST_Response(array('ok' => false, 'message' => 'The primary Discord server is not configured.'), 503);
        if (!$this->user_has_guild_join_scope($target_user_id)) {
            return new WP_REST_Response(array(
                'ok' => false,
                'message' => 'That user must open their Discord Account page in the portal, choose Grant server restore permission, and approve guilds.join before they can be restored.',
            ), 409);
        }
        if (!$this->force_join_primary_guild($target_user_id)) {
            return new WP_REST_Response(array('ok' => false, 'message' => 'Discord could not add that account even though server-restore permission is recorded. Check the bot and server configuration.'), 502);
        }
        $this->safe_run_single_user_sync($target_user_id);
        $this->log_configured_activity_message($this->format_activity_message('discord_member_restore', array(
            'summary' => 'A Discord member was directly restored to the primary server.',
            'user_id' => $target_user_id,
            'actor_user_id' => $actor_user_id,
        )));
        $target = get_userdata($target_user_id);
        return new WP_REST_Response(array('ok' => true, 'message' => ($target ? ($target->display_name ?: $target->user_login) : 'The user') . ' was added back to the primary Discord server.'));
    }

    public function rest_discord_permissions_command($request) {
        $secret = (string) $request->get_header('x-edb-secret');
        $expected_secret = (string) get_option(self::RESET_SECRET_OPTION);
        if ($secret === '' || $expected_secret === '' || !hash_equals($expected_secret, $secret)) {
            return new WP_REST_Response(array('ok' => false, 'message' => 'Unauthorized'), 401);
        }

        $actor_discord_id = sanitize_text_field((string) $request->get_param('discord_user_id'));
        $actor_user_id = (int) $this->find_user_by_discord_id_only($actor_discord_id);
        if (!$actor_user_id) {
            return new WP_REST_Response(array('ok' => false, 'message' => 'Your Discord account is not linked to a portal user.'), 404);
        }

        $target_discord_id = sanitize_text_field((string) $request->get_param('target_discord_user_id'));
        $target_reference = sanitize_text_field((string) $request->get_param('target'));
        if ($target_discord_id !== '') {
            $target_user_id = (int) $this->find_user_by_discord_id_only($target_discord_id);
        } elseif ($target_reference !== '') {
            $target_user_id = (int) $this->resolve_portal_user_reference($target_reference, '');
        } else {
            $target_user_id = $actor_user_id;
        }

        if (!$target_user_id || !get_userdata($target_user_id)) {
            return new WP_REST_Response(array('ok' => false, 'message' => 'No linked portal account was found for that Discord user.'), 404);
        }
        if ($actor_user_id !== $target_user_id && !$this->can_restore_discord_member_for($actor_user_id, $target_user_id)) {
            return new WP_REST_Response(array('ok' => false, 'message' => 'You can only view Discord permissions for members in your management scope.'), 403);
        }

        $linked_discord_id = trim((string) get_user_meta($target_user_id, 'edb_discord_user_id', true));
        if ($linked_discord_id === '' || empty(get_user_meta($target_user_id, 'edb_discord_linked', true))) {
            return new WP_REST_Response(array('ok' => false, 'message' => 'That portal user has not connected a Discord account.'), 404);
        }

        $scope_string = trim((string) get_user_meta($target_user_id, 'edb_discord_scope', true));
        $scopes = preg_split('/\s+/', $scope_string, -1, PREG_SPLIT_NO_EMPTY);
        $scopes = is_array($scopes) ? array_values(array_unique(array_map('sanitize_text_field', $scopes))) : array();
        return new WP_REST_Response(array(
            'ok' => true,
            'display_name' => $this->portal_user_label($target_user_id),
            'discord_user_id' => $linked_discord_id,
            'scope_recorded' => $scope_string !== '',
            'granted_scopes' => $scopes,
            'has_guild_join' => in_array('guilds.join', $scopes, true),
        ));
    }

    protected function can_restore_discord_member_for($actor_user_id, $target_user_id) {
        $actor_user_id = (int)$actor_user_id;
        $target_user_id = (int)$target_user_id;
        if ($actor_user_id <= 0 || $target_user_id <= 0) return false;
        if (user_can($actor_user_id, 'manage_options')) return true;
        if (class_exists('EUM_Access') && EUM_Access::is_it_or_hr($actor_user_id)) return true;
        return class_exists('EUM_Manager_IDs') && EUM_Manager_IDs::user_is_manager_of_user($actor_user_id, $target_user_id);
    }

    public function rest_guild_refresh($request) {
        $secret = $request->get_header('x-edb-secret');
        if (!$secret || $secret !== get_option(self::RESET_SECRET_OPTION)) {
            return new WP_REST_Response(array('ok' => false, 'message' => 'Unauthorized'), 401);
        }
        $user_ids = $request->get_param('user_ids');
        $user_ids = is_array($user_ids) ? array_map('absint', $user_ids) : array();
        if (empty($user_ids)) {
            foreach ($this->get_linked_users() as $user) {
                $this->refresh_user_guild_cache($user->ID);
            }
        } else {
            foreach ($user_ids as $user_id) {
                $this->refresh_user_guild_cache($user_id);
            }
        }
        return new WP_REST_Response(array('ok' => true));
    }

    public function rest_automation_pass($request) {
        try {
            if (!$this->bot_request_is_authorized($request)) {
                return new WP_REST_Response(array('ok' => false, 'message' => 'Unauthorized'), 401);
            }
            $result = $this->run_bot_automation_cycle();
            $this->log_configured_activity_message($this->format_activity_message('bot_automation', array(
                'summary' => 'Bot automation pass completed.',
                'details' => $result,
            )));
            return new WP_REST_Response(array_merge(array('ok' => true), $result));
        } catch (Throwable $e) {
            return new WP_REST_Response(array(
                'ok' => false,
                'message' => 'Automation pass failed.',
                'detail' => $e->getMessage(),
            ), 500);
        }
    }

    public function rest_profile_command($request) {
        try {
            if (!$this->bot_request_is_authorized($request)) {
                return new WP_REST_Response(array('ok' => false, 'message' => 'Unauthorized'), 401);
            }
            $discord_user_id = sanitize_text_field((string) $request->get_param('discord_user_id'));
            $command = sanitize_key((string) $request->get_param('command'));
            if ($discord_user_id === '') {
                return new WP_REST_Response(array('ok' => false, 'message' => 'discord_user_id is required'), 400);
            }
            if ($command === '') {
                $command = 'view';
            }
            $actor_user_id = $this->find_user_by_discord_id_only($discord_user_id);
            if (!$actor_user_id) {
                return new WP_REST_Response(array('ok' => false, 'message' => 'No linked portal user found'), 404);
            }
            $target_user_id = $this->resolve_portal_user_reference((string) $request->get_param('target'), $discord_user_id);
            if ($target_user_id <= 0) {
                return new WP_REST_Response(array('ok' => false, 'message' => 'Target user not found'), 404);
            }

            if (in_array($command, array('view', 'info', 'profile'), true)) {
                if (!$this->bot_can_view_profile($actor_user_id, $target_user_id)) {
                    return new WP_REST_Response(array('ok' => false, 'message' => 'You can only view yourself or your direct reports.'), 403);
                }
                $fields = $request->get_param('fields');
                $fields = is_array($fields) ? array_map('strval', $fields) : array();
                $this->log_configured_activity_message($this->format_activity_message('bot_profile_view', array(
                    'summary' => 'Discord bot viewed a profile.',
                    'actor_user_id' => $actor_user_id,
                    'user_id' => $target_user_id,
                )));
                return new WP_REST_Response(array(
                    'ok' => true,
                    'user_id' => $target_user_id,
                    'profile' => $this->bot_profile_payload($target_user_id, $fields),
                ));
            }

            if ($command === 'update' || $command === 'set_fields') {
                if (!$this->bot_can_manage_profile($actor_user_id, $target_user_id)) {
                    return new WP_REST_Response(array('ok' => false, 'message' => 'You do not have permission to change that profile.'), 403);
                }
                $fields = $request->get_param('fields');
                if (!is_array($fields) || empty($fields)) {
                    return new WP_REST_Response(array('ok' => false, 'message' => 'fields must be a non-empty object'), 400);
                }
                $result = $this->submit_profile_change_from_bot(
                    $actor_user_id,
                    $target_user_id,
                    $fields,
                    sanitize_text_field((string) $request->get_param('reason')),
                    sanitize_text_field((string) $request->get_param('effective_at'))
                );
                if (is_wp_error($result)) {
                    return new WP_REST_Response(array(
                        'ok' => false,
                        'message' => $result->get_error_message(),
                        'code' => $result->get_error_code(),
                    ), 400);
                }
                $this->safe_run_single_user_sync($target_user_id);
                $this->log_configured_activity_message($this->format_activity_message('bot_profile_update', array(
                    'summary' => 'Discord bot submitted a profile change.',
                    'actor_user_id' => $actor_user_id,
                    'user_id' => $target_user_id,
                    'details' => $result,
                )));
                return new WP_REST_Response(array_merge(array(
                    'ok' => true,
                    'user_id' => $target_user_id,
                    'display_name' => $this->portal_user_label($target_user_id),
                ), $result));
            }

            return new WP_REST_Response(array('ok' => false, 'message' => 'Unknown profile command'), 400);
        } catch (Throwable $e) {
            return new WP_REST_Response(array(
                'ok' => false,
                'message' => 'Profile command failed.',
                'detail' => $e->getMessage(),
            ), 500);
        }
    }

    public function rest_leave_command($request) {
        try {
            if (!$this->bot_request_is_authorized($request)) {
                return new WP_REST_Response(array('ok' => false, 'message' => 'Unauthorized'), 401);
            }
            $discord_user_id = sanitize_text_field((string) $request->get_param('discord_user_id'));
            if ($discord_user_id === '') {
                return new WP_REST_Response(array('ok' => false, 'message' => 'discord_user_id is required'), 400);
            }
            $actor_user_id = $this->find_user_by_discord_id_only($discord_user_id);
            if (!$actor_user_id) {
                return new WP_REST_Response(array('ok' => false, 'message' => 'No linked portal user found'), 404);
            }
            $target_user_id = $this->resolve_portal_user_reference((string) $request->get_param('target'), $discord_user_id);
            if ($target_user_id <= 0) {
                return new WP_REST_Response(array('ok' => false, 'message' => 'Target user not found'), 404);
            }
            $result = $this->submit_leave_request_from_bot($actor_user_id, $target_user_id, $request);
            if (is_wp_error($result)) {
                return new WP_REST_Response(array(
                    'ok' => false,
                    'message' => $result->get_error_message(),
                    'code' => $result->get_error_code(),
                ), 400);
            }
            $this->queue_sync_request($target_user_id, 'leave_request', true);
            $this->log_configured_activity_message($this->format_activity_message('bot_leave', array(
                'summary' => 'Discord bot submitted a leave request.',
                'actor_user_id' => $actor_user_id,
                'user_id' => $target_user_id,
                'details' => $result,
            )));
            return new WP_REST_Response(array_merge(array(
                'ok' => true,
                'user_id' => $target_user_id,
                'display_name' => $this->portal_user_label($target_user_id),
            ), $result));
        } catch (Throwable $e) {
            return new WP_REST_Response(array(
                'ok' => false,
                'message' => 'Leave command failed.',
                'detail' => $e->getMessage(),
            ), 500);
        }
    }

    public function rest_availability_command($request) {
        try {
            if (!$this->bot_request_is_authorized($request)) {
                return new WP_REST_Response(array('ok' => false, 'message' => 'Unauthorized'), 401);
            }
            $discord_user_id = sanitize_text_field((string) $request->get_param('discord_user_id'));
            if ($discord_user_id === '') {
                return new WP_REST_Response(array('ok' => false, 'message' => 'discord_user_id is required'), 400);
            }
            $actor_user_id = $this->find_user_by_discord_id_only($discord_user_id);
            if (!$actor_user_id) {
                return new WP_REST_Response(array('ok' => false, 'message' => 'No linked portal user found'), 404);
            }
            $target_user_id = $this->resolve_portal_user_reference((string) $request->get_param('target'), $discord_user_id);
            if ($target_user_id <= 0) {
                return new WP_REST_Response(array('ok' => false, 'message' => 'Target user not found'), 404);
            }
            $result = $this->submit_availability_request_from_bot($actor_user_id, $target_user_id, $request);
            if (is_wp_error($result)) {
                return new WP_REST_Response(array(
                    'ok' => false,
                    'message' => $result->get_error_message(),
                    'code' => $result->get_error_code(),
                ), 400);
            }
            $this->queue_sync_request($target_user_id, 'availability_request', true);
            $this->log_configured_activity_message($this->format_activity_message('bot_availability', array(
                'summary' => 'Discord bot submitted an availability request.',
                'actor_user_id' => $actor_user_id,
                'user_id' => $target_user_id,
                'details' => $result,
            )));
            return new WP_REST_Response(array_merge(array(
                'ok' => true,
                'user_id' => $target_user_id,
                'display_name' => $this->portal_user_label($target_user_id),
            ), $result));
        } catch (Throwable $e) {
            return new WP_REST_Response(array(
                'ok' => false,
                'message' => 'Availability command failed.',
                'detail' => $e->getMessage(),
            ), 500);
        }
    }

    public function rest_attention_command($request) {
        try {
            if (!$this->bot_request_is_authorized($request)) {
                return new WP_REST_Response(array('ok' => false, 'message' => 'Unauthorized'), 401);
            }
            $discord_user_id = sanitize_text_field((string) $request->get_param('discord_user_id'));
            $message = trim((string) $request->get_param('message'));
            if ($discord_user_id === '' || $message === '') {
                return new WP_REST_Response(array('ok' => false, 'message' => 'discord_user_id and message are required'), 400);
            }
            $actor_user_id = $this->find_user_by_discord_id_only($discord_user_id);
            if (!$actor_user_id) {
                return new WP_REST_Response(array('ok' => false, 'message' => 'No linked portal user found'), 404);
            }
            $target_user_id = $this->resolve_portal_user_reference((string) $request->get_param('target'), '');
            if ($target_user_id <= 0) {
                return new WP_REST_Response(array('ok' => false, 'message' => 'Target user not found'), 404);
            }
            if (!$this->bot_can_send_attention($actor_user_id, $target_user_id)) {
                return new WP_REST_Response(array('ok' => false, 'message' => 'You do not have permission to message that user.'), 403);
            }
            $result = $this->queue_portal_user_dm($target_user_id, $message, $actor_user_id, 'manual_attention');
            if (is_wp_error($result)) {
                return new WP_REST_Response(array(
                    'ok' => false,
                    'message' => $result->get_error_message(),
                    'code' => $result->get_error_code(),
                ), 400);
            }
            $this->log_configured_activity_message($this->format_activity_message('bot_attention', array(
                'summary' => 'Discord bot sent an attention message.',
                'actor_user_id' => $actor_user_id,
                'user_id' => $target_user_id,
                'details' => $result,
            )));
            return new WP_REST_Response(array(
                'ok' => true,
                'user_id' => $target_user_id,
                'display_name' => $this->portal_user_label($target_user_id),
                'delivery' => $result,
            ));
        } catch (Throwable $e) {
            return new WP_REST_Response(array(
                'ok' => false,
                'message' => 'Attention command failed.',
                'detail' => $e->getMessage(),
            ), 500);
        }
    }

    public function rest_timeclock_command($request) {
        try {
            if (!$this->bot_request_is_authorized($request)) {
                return new WP_REST_Response(array('ok' => false, 'message' => 'Unauthorized'), 401);
            }
            $discord_user_id = sanitize_text_field((string) $request->get_param('discord_user_id'));
            $command = $this->normalize_bot_timeclock_command((string) $request->get_param('command'));
            if ($discord_user_id === '' || $command === '') {
                return new WP_REST_Response(array('ok' => false, 'message' => 'discord_user_id and command are required'), 400);
            }
            $actor_user_id = $this->find_user_by_discord_id_only($discord_user_id);
            if (!$actor_user_id) {
                return new WP_REST_Response(array('ok' => false, 'message' => 'No linked portal user found'), 404);
            }
            $target_user_id = $this->resolve_portal_user_reference((string) $request->get_param('target'), $discord_user_id);
            if ($target_user_id <= 0) {
                return new WP_REST_Response(array('ok' => false, 'message' => 'Target user not found'), 404);
            }

            if (in_array($command, array('clock_in', 'clock_out', 'toggle'), true)) {
                if ($target_user_id !== $actor_user_id && !$this->bot_can_manage_timecard($actor_user_id, $target_user_id)) {
                    return new WP_REST_Response(array('ok' => false, 'message' => 'You do not have permission to manage that timecard.'), 403);
                }
                $result = $this->perform_timeclock_command_for_user($target_user_id, $command, $actor_user_id);
                if (is_wp_error($result)) {
                    return new WP_REST_Response(array(
                        'ok' => false,
                        'message' => $result->get_error_message(),
                        'code' => $result->get_error_code(),
                        'user_id' => $target_user_id,
                        'currently_clocked_in' => $this->user_is_currently_clocked_in($target_user_id),
                    ), 400);
                }
                $this->safe_run_single_user_sync($target_user_id);
                $this->log_configured_activity_message($this->format_activity_message('bot_timeclock', array(
                    'summary' => 'Discord bot ran a timeclock command.',
                    'actor_user_id' => $actor_user_id,
                    'user_id' => $target_user_id,
                    'details' => $result,
                )));
                return new WP_REST_Response(array_merge(array(
                    'ok' => true,
                    'user_id' => $target_user_id,
                    'display_name' => $this->portal_user_label($target_user_id),
                    'currently_clocked_in' => $this->user_is_currently_clocked_in($target_user_id),
                ), $result));
            }

            if ($command === 'view_day') {
                if (!$this->bot_can_view_timecard($actor_user_id, $target_user_id)) {
                    return new WP_REST_Response(array('ok' => false, 'message' => 'You do not have permission to view that timecard.'), 403);
                }
                $date = sanitize_text_field((string) $request->get_param('date'));
                if ($date === '') {
                    $date = $this->current_local_date_for_user($target_user_id);
                }
                $entries = $this->timecard_entries_for_local_day($target_user_id, $date);
                if (is_wp_error($entries)) {
                    return new WP_REST_Response(array(
                        'ok' => false,
                        'message' => $entries->get_error_message(),
                        'code' => $entries->get_error_code(),
                    ), 400);
                }
                $this->log_configured_activity_message($this->format_activity_message('bot_timecard_view', array(
                    'summary' => 'Discord bot viewed a timecard day.',
                    'actor_user_id' => $actor_user_id,
                    'user_id' => $target_user_id,
                    'details' => array('date' => $date),
                )));
                return new WP_REST_Response(array(
                    'ok' => true,
                    'user_id' => $target_user_id,
                    'display_name' => $this->portal_user_label($target_user_id),
                    'date' => $date,
                    'entries' => $entries,
                ));
            }

            if ($command === 'add_punch') {
                if (!$this->bot_can_manage_timecard($actor_user_id, $target_user_id)) {
                    return new WP_REST_Response(array('ok' => false, 'message' => 'You do not have permission to manage that timecard.'), 403);
                }
                $result = $this->bot_add_timecard_entry($actor_user_id, $target_user_id, $request);
                if (is_wp_error($result)) {
                    return new WP_REST_Response(array(
                        'ok' => false,
                        'message' => $result->get_error_message(),
                        'code' => $result->get_error_code(),
                    ), 400);
                }
                $this->safe_run_single_user_sync($target_user_id);
                $this->log_configured_activity_message($this->format_activity_message('bot_timecard_add', array(
                    'summary' => 'Discord bot added a timecard punch.',
                    'actor_user_id' => $actor_user_id,
                    'user_id' => $target_user_id,
                    'details' => $result,
                )));
                return new WP_REST_Response(array_merge(array(
                    'ok' => true,
                    'user_id' => $target_user_id,
                    'display_name' => $this->portal_user_label($target_user_id),
                ), $result));
            }

            if ($command === 'delete_entry') {
                if (!$this->bot_can_manage_timecard($actor_user_id, $target_user_id)) {
                    return new WP_REST_Response(array('ok' => false, 'message' => 'You do not have permission to manage that timecard.'), 403);
                }
                $result = $this->bot_delete_timecard_entry($target_user_id, (int) $request->get_param('entry_id'));
                if (is_wp_error($result)) {
                    return new WP_REST_Response(array(
                        'ok' => false,
                        'message' => $result->get_error_message(),
                        'code' => $result->get_error_code(),
                    ), 400);
                }
                $this->safe_run_single_user_sync($target_user_id);
                $this->log_configured_activity_message($this->format_activity_message('bot_timecard_delete', array(
                    'summary' => 'Discord bot deleted a timecard entry.',
                    'actor_user_id' => $actor_user_id,
                    'user_id' => $target_user_id,
                    'details' => array('entry_id' => (int) $request->get_param('entry_id')),
                )));
                return new WP_REST_Response(array(
                    'ok' => true,
                    'user_id' => $target_user_id,
                    'display_name' => $this->portal_user_label($target_user_id),
                    'deleted' => $result,
                ));
            }

            if ($command === 'clear_day') {
                if (!$this->bot_can_manage_timecard($actor_user_id, $target_user_id)) {
                    return new WP_REST_Response(array('ok' => false, 'message' => 'You do not have permission to manage that timecard.'), 403);
                }
                $date = sanitize_text_field((string) $request->get_param('date'));
                if ($date === '') {
                    $date = $this->current_local_date_for_user($target_user_id);
                }
                $result = $this->bot_clear_timecard_day($target_user_id, $date);
                if (is_wp_error($result)) {
                    return new WP_REST_Response(array(
                        'ok' => false,
                        'message' => $result->get_error_message(),
                        'code' => $result->get_error_code(),
                    ), 400);
                }
                $this->safe_run_single_user_sync($target_user_id);
                $this->log_configured_activity_message($this->format_activity_message('bot_timecard_clear', array(
                    'summary' => 'Discord bot cleared a timecard day.',
                    'actor_user_id' => $actor_user_id,
                    'user_id' => $target_user_id,
                    'details' => array('date' => $date, 'deleted_count' => (int) $result),
                )));
                return new WP_REST_Response(array(
                    'ok' => true,
                    'user_id' => $target_user_id,
                    'display_name' => $this->portal_user_label($target_user_id),
                    'date' => $date,
                    'deleted_count' => (int) $result,
                ));
            }

            return new WP_REST_Response(array('ok' => false, 'message' => 'Unknown timeclock command'), 400);
        } catch (Throwable $e) {
            return new WP_REST_Response(array(
                'ok' => false,
                'message' => 'Timeclock command failed during ' . sanitize_key((string) $request->get_param('command')) . '.',
                'detail' => $e->getMessage(),
            ), 500);
        }
    }

    protected function perform_timeclock_command_for_user($user_id, $command, $actor_user_id = 0) {
        $user_id = absint($user_id);
        if ($user_id <= 0) {
            return new WP_Error('invalid_user', 'User not found.');
        }
        if (!class_exists('EUM_Timeclock')) {
            return new WP_Error('missing_timeclock', 'The Enterprise timeclock plugin is not active.');
        }
        $actor_user_id = absint($actor_user_id);
        if ($actor_user_id <= 0) {
            $actor_user_id = $user_id;
        }
        if ($command === 'toggle') {
            $command = $this->user_is_currently_clocked_in($user_id) ? 'clock_out' : 'clock_in';
        }
        if (!in_array($command, array('clock_in', 'clock_out'), true)) {
            return new WP_Error('invalid_command', 'Command must be clock_in, clock_out, or toggle.');
        }
        $timeclock = new EUM_Timeclock();
        return $this->invoke_timeclock_nonpublic($timeclock, 'record_clock_command', array($user_id, $actor_user_id, $command, 'discord'));
    }

    protected function bot_request_is_authorized($request) {
        $secret = $request->get_header('x-edb-secret');
        return !empty($secret) && $secret === get_option(self::RESET_SECRET_OPTION);
    }

    protected function normalize_bot_timeclock_command($command) {
        $command = sanitize_key((string) $command);
        $map = array(
            'in' => 'clock_in',
            'out' => 'clock_out',
            'clockin' => 'clock_in',
            'clockout' => 'clock_out',
            'view' => 'view_day',
            'day' => 'view_day',
            'add' => 'add_punch',
            'delete' => 'delete_entry',
            'clear' => 'clear_day',
        );
        return isset($map[$command]) ? $map[$command] : $command;
    }

    protected function resolve_portal_user_reference($reference, $fallback_discord_user_id = '') {
        $reference = trim((string) $reference);
        $fallback_discord_user_id = trim((string) $fallback_discord_user_id);
        if ($reference === '' && $fallback_discord_user_id !== '') {
            return (int) $this->find_user_by_discord_id_only($fallback_discord_user_id);
        }
        if ($reference === '') {
            return 0;
        }
        $discord_id = $this->extract_discord_snowflake($reference);
        if ($discord_id !== '') {
            $matched = (int) $this->find_user_by_discord_id_only($discord_id);
            if ($matched > 0) {
                return $matched;
            }
        }
        if (class_exists('EUM_Workforce_Lifecycle') && method_exists('EUM_Workforce_Lifecycle', 'resolve_user_reference')) {
            $matched = (int) EUM_Workforce_Lifecycle::resolve_user_reference($reference, true);
            if ($matched > 0) {
                return $matched;
            }
        }
        $user = get_user_by('login', sanitize_user($reference));
        if (!$user) {
            $user = get_user_by('email', sanitize_email($reference));
        }
        return $user ? (int) $user->ID : 0;
    }

    protected function portal_user_label($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return 'User #' . $user_id;
        }
        if (class_exists('EUM_Workforce_Lifecycle') && method_exists('EUM_Workforce_Lifecycle', 'employee_list_label')) {
            return (string) EUM_Workforce_Lifecycle::employee_list_label($user_id);
        }
        $user = get_userdata($user_id);
        return $user ? (string) ($user->display_name ?: $user->user_login) : ('User #' . $user_id);
    }

    protected function bot_can_view_profile($actor_user_id, $target_user_id) {
        $actor_user_id = (int) $actor_user_id;
        $target_user_id = (int) $target_user_id;
        if ($actor_user_id <= 0 || $target_user_id <= 0) {
            return false;
        }
        if ($actor_user_id === $target_user_id) {
            return true;
        }
        if (class_exists('EUM_Access') && EUM_Access::is_it_or_hr($actor_user_id)) {
            return true;
        }
        return class_exists('EUM_Manager_IDs') && EUM_Manager_IDs::user_is_direct_manager_of_user($actor_user_id, $target_user_id);
    }

    protected function bot_can_manage_profile($actor_user_id, $target_user_id) {
        return class_exists('EUM_Profiles') && EUM_Profiles::can_edit_target((int) $actor_user_id, (int) $target_user_id);
    }

    protected function bot_can_send_attention($actor_user_id, $target_user_id) {
        $actor_user_id = (int) $actor_user_id;
        $target_user_id = (int) $target_user_id;
        if ($actor_user_id <= 0 || $target_user_id <= 0 || $actor_user_id === $target_user_id) {
            return false;
        }
        if (class_exists('EUM_Access') && EUM_Access::is_it_or_hr($actor_user_id)) {
            return true;
        }
        if ($this->bot_can_manage_profile($actor_user_id, $target_user_id)) {
            return true;
        }
        return class_exists('EUM_Manager_IDs') && EUM_Manager_IDs::user_is_direct_manager_of_user($actor_user_id, $target_user_id);
    }

    protected function bot_can_view_timecard($actor_user_id, $target_user_id) {
        $actor_user_id = (int) $actor_user_id;
        $target_user_id = (int) $target_user_id;
        if ($actor_user_id <= 0 || $target_user_id <= 0) {
            return false;
        }
        if ($actor_user_id === $target_user_id) {
            return true;
        }
        if (!class_exists('EUM_Timeclock')) {
            return false;
        }
        $timeclock = new EUM_Timeclock();
        return (bool) $this->invoke_nonpublic_method($timeclock, 'can_view_timecard', array($actor_user_id, $target_user_id));
    }

    protected function bot_can_manage_timecard($actor_user_id, $target_user_id) {
        $actor_user_id = (int) $actor_user_id;
        $target_user_id = (int) $target_user_id;
        if ($actor_user_id <= 0 || $target_user_id <= 0) {
            return false;
        }
        if ($actor_user_id === $target_user_id) {
            return true;
        }
        if (!class_exists('EUM_Timeclock')) {
            return false;
        }
        $timeclock = new EUM_Timeclock();
        return (bool) $this->invoke_nonpublic_method($timeclock, 'can_edit_timecard', array($actor_user_id, $target_user_id));
    }

    protected function bot_profile_payload($user_id, array $requested_fields = array()) {
        $user_id = (int) $user_id;
        $user = get_userdata($user_id);
        if (!$user) {
            return array();
        }
        $payload = array(
            'display_name' => (string) ($user->display_name ?: $user->user_login),
            'employee_id' => class_exists('EUM_Workforce_Lifecycle') && method_exists('EUM_Workforce_Lifecycle', 'employee_id_for_user') ? (string) EUM_Workforce_Lifecycle::employee_id_for_user($user_id) : '',
            'work_email' => (string) $user->user_email,
            'job_title' => class_exists('EUM_Profiles') ? (string) EUM_Profiles::preferred_job_title_label($user_id) : '',
            'company_division' => class_exists('EUM_Profiles') ? (string) EUM_Profiles::get_field_display_value($user_id, 'core:job_company') : '',
            'job_path' => class_exists('EUM_Profiles') ? (string) EUM_Profiles::get_field_display_value($user_id, 'tax:eum_job_path') : '',
            'location' => class_exists('EUM_Profiles') ? (string) EUM_Profiles::get_field_display_value($user_id, 'tax:eum_location') : '',
            'manager' => class_exists('EUM_Profiles') ? (string) EUM_Profiles::get_field_display_value($user_id, 'core:eum_manager_user_id') : '',
            'worker_type' => class_exists('EUM_Profiles') ? (string) EUM_Profiles::get_field_display_value($user_id, 'custom:worker_type') : '',
            'status' => class_exists('EUM_Workforce_Lifecycle') && method_exists('EUM_Workforce_Lifecycle', 'employment_status_label_for_user') ? (string) EUM_Workforce_Lifecycle::employment_status_label_for_user($user_id, $user_id) : '',
        );
        if (empty($requested_fields) || !class_exists('EUM_Profiles')) {
            return $payload;
        }
        $catalog = EUM_Profiles::field_catalog();
        foreach ($requested_fields as $field_key) {
            $field_id = $this->normalize_profile_field_id($field_key, $catalog);
            if ($field_id === '' || !isset($catalog[$field_id])) {
                continue;
            }
            $payload[$field_id] = (string) EUM_Profiles::get_field_display_value($user_id, $field_id);
        }
        return $payload;
    }

    protected function normalize_profile_field_id($field_key, array $catalog = array()) {
        $field_key = trim((string) $field_key);
        if ($field_key === '') {
            return '';
        }
        if (isset($catalog[$field_key])) {
            return $field_key;
        }
        $raw = str_replace(' ', '_', strtolower($field_key));
        $core_keys = array('first_name', 'last_name', 'display_name', 'user_email', 'user_pass', 'eum_manager_user_id', 'job_company');
        $tax_keys = array('eum_job_path', 'eum_location', 'eum_user_group', 'eum_project');
        if (in_array($raw, $core_keys, true) && isset($catalog['core:' . $raw])) {
            return 'core:' . $raw;
        }
        if (in_array($raw, $tax_keys, true) && isset($catalog['tax:' . $raw])) {
            return 'tax:' . $raw;
        }
        if (isset($catalog['custom:' . $raw])) {
            return 'custom:' . $raw;
        }
        if (str_starts_with($raw, 'system:')) {
            return $raw;
        }
        return '';
    }

    protected function submit_profile_change_from_bot($actor_user_id, $target_user_id, array $fields, $reason = '', $effective_at_raw = '') {
        if (!class_exists('EUM_Profiles')) {
            return new WP_Error('missing_profiles', 'The Enterprise profile tools are not active.');
        }
        $actor_user_id = (int) $actor_user_id;
        $target_user_id = (int) $target_user_id;
        if ($actor_user_id <= 0 || $target_user_id <= 0) {
            return new WP_Error('invalid_user', 'User not found.');
        }

        $profiles = new EUM_Profiles();
        $catalog = EUM_Profiles::field_catalog();
        $editable = array_flip(EUM_Profiles::editable_fields_for_actor($actor_user_id, $target_user_id));
        if (empty($editable)) {
            return new WP_Error('not_editable', 'No editable profile fields are available for that actor.');
        }

        $before = array();
        $after = array();
        foreach ($fields as $input_key => $value) {
            $field_id = $this->normalize_profile_field_id($input_key, $catalog);
            if ($field_id === '') {
                return new WP_Error('invalid_field', 'Unknown profile field: ' . (string) $input_key);
            }
            if (str_starts_with($field_id, 'system:eum_terminated')) {
                return new WP_Error('field_blocked', 'Termination changes are not allowed through the Discord command.');
            }
            if (!isset($editable[$field_id])) {
                return new WP_Error('field_blocked', 'You cannot edit ' . $field_id . ' for that user.');
            }
            $before[$field_id] = EUM_Profiles::get_field_raw_value($target_user_id, $field_id);
            $after[$field_id] = $this->invoke_nonpublic_method($profiles, 'normalize_posted_field', array($field_id, $catalog[$field_id], $value));
        }
        if (empty($after)) {
            return new WP_Error('missing_fields', 'No profile fields were provided.');
        }

        if (!class_exists('EUM_Access') || !EUM_Access::is_it_or_hr($actor_user_id)) {
            if ($actor_user_id !== $target_user_id && array_key_exists('tax:eum_job_path', $after)) {
                $new_job_term = (int) $after['tax:eum_job_path'];
                if ($new_job_term > 0) {
                    $allowed = (bool) $this->invoke_nonpublic_static('EUM_Profiles', 'actor_can_manage_job_term', array($actor_user_id, $new_job_term));
                    if (!$allowed) {
                        return new WP_Error('job_path_forbidden', 'Managers can only move job titles within branches they manage.');
                    }
                }
            }
        }

        if (array_key_exists('tax:eum_job_path', $after)) {
            $new_job_term = (int) $after['tax:eum_job_path'];
            if ($new_job_term > 0 && class_exists('EUM_Company_Divisions')) {
                $target_user = get_userdata($target_user_id);
                $username_basis = $target_user ? (string) $target_user->user_login : '';
                $override_division = class_exists('EUM_Access') && EUM_Access::is_it_or_hr($actor_user_id) ? (string) ($after['core:job_company'] ?? '') : '';
                $derived = EUM_Company_Divisions::derive_transfer_values($username_basis, $new_job_term, $override_division);
                if (!empty($derived['division'])) {
                    $before['core:job_company'] = EUM_Profiles::get_field_raw_value($target_user_id, 'core:job_company');
                    $after['core:job_company'] = $derived['division'];
                }
                if ((string) ($derived['email'] ?? '') === '') {
                    return new WP_Error('missing_division_email', 'That Job Path does not resolve to a Company Division email domain.');
                }
                $before['core:user_email'] = EUM_Profiles::get_field_raw_value($target_user_id, 'core:user_email');
                $after['core:user_email'] = (string) $derived['email'];
            }
        }

        if (class_exists('EUM_Taxonomies') && (array_key_exists('tax:eum_job_path', $after) || array_key_exists('tax:eum_location', $after))) {
            $effective_mapping = EUM_Taxonomies::effective_term_meta_mapping_for_user_state($target_user_id, $after);
            $mapping_sets = EUM_Taxonomies::mapping_change_set_for_user($target_user_id, $effective_mapping);
            $mapping_before = isset($mapping_sets[0]) && is_array($mapping_sets[0]) ? $mapping_sets[0] : array();
            $mapping_after = isset($mapping_sets[1]) && is_array($mapping_sets[1]) ? $mapping_sets[1] : array();
            foreach ($mapping_after as $field_id => $value) {
                $before[$field_id] = isset($mapping_before[$field_id]) ? $mapping_before[$field_id] : (isset($before[$field_id]) ? $before[$field_id] : null);
                $after[$field_id] = $value;
            }
        }

        $has_changes = false;
        foreach ($after as $field_id => $value) {
            if ($this->invoke_nonpublic_method($profiles, 'values_differ', array(isset($before[$field_id]) ? $before[$field_id] : null, $value))) {
                $has_changes = true;
                break;
            }
        }
        if (!$has_changes) {
            return new WP_Error('no_changes', 'No profile changes were detected.');
        }

        $allowed_reasons = EUM_Profiles::reason_options_for_actor($actor_user_id, $target_user_id);
        $reason = sanitize_text_field((string) $reason);
        if ($reason === '') {
            $reason = !empty($allowed_reasons) ? (string) reset($allowed_reasons) : '';
        }
        if ($reason === '' || !in_array($reason, $allowed_reasons, true)) {
            return new WP_Error('invalid_reason', 'Use a valid profile change reason for that actor.');
        }

        $effective_at = EUM_Profiles::parse_datetime_local((string) $effective_at_raw);
        $transfer_effective = $this->invoke_nonpublic_static('EUM_Profiles', 'normalize_transfer_effective_at_for_save', array($actor_user_id, $target_user_id, $effective_at, $after, 0));
        if (!empty($transfer_effective['error'])) {
            return new WP_Error('transfer_error', (string) $transfer_effective['error']);
        }
        if (!empty($transfer_effective['is_transfer'])) {
            $effective_at = (string) $transfer_effective['effective_at'];
        } elseif (!class_exists('EUM_Access') || (!EUM_Access::is_it_or_hr($actor_user_id) && $actor_user_id !== $target_user_id)) {
            $settings = EUM_Profiles::get_settings();
            if ($effective_at === '' && !empty($settings['force_next_sunday'])) {
                $effective_at = (string) $this->invoke_nonpublic_static('EUM_Profiles', 'next_sunday_midnight', array());
            }
        }

        $transfer_context = $this->invoke_nonpublic_static('EUM_Profiles', 'transfer_protocol_context', array($target_user_id, $after, 0));
        $requires_approval = EUM_Profiles::manager_change_requires_approval($actor_user_id, $target_user_id, array_keys($after));
        $approver_ids = $requires_approval ? EUM_Profiles::approver_ids_for_actor($actor_user_id, $target_user_id, $after, 0) : array();
        $status = $requires_approval ? 'pending' : ($effective_at !== '' ? 'scheduled' : 'applied');

        $target_user = get_userdata($target_user_id);
        $change_id = (int) wp_insert_post(array(
            'post_type' => EUM_Profiles::CPT_CHANGE,
            'post_status' => 'publish',
            'post_title' => 'Profile Change - ' . ($target_user ? ($target_user->display_name ?: $target_user->user_login) : ('User #' . $target_user_id)),
            'post_author' => $actor_user_id,
        ));
        if ($change_id <= 0) {
            return new WP_Error('save_failed', 'Could not save the profile change.');
        }

        update_post_meta($change_id, EUM_Profiles::META_TARGET_USER, $target_user_id);
        update_post_meta($change_id, EUM_Profiles::META_ACTOR_USER, $actor_user_id);
        update_post_meta($change_id, EUM_Profiles::META_APPROVER_IDS, $approver_ids);
        update_post_meta($change_id, EUM_Profiles::META_BEFORE, $before);
        update_post_meta($change_id, EUM_Profiles::META_AFTER, $after);
        update_post_meta($change_id, EUM_Profiles::META_NOTE, $reason);
        update_post_meta($change_id, EUM_Profiles::META_EFFECTIVE_AT, $effective_at);
        update_post_meta($change_id, EUM_Profiles::META_OCCURRED_AT, current_time('mysql'));
        update_post_meta($change_id, EUM_Profiles::META_STATUS, $status);
        update_post_meta($change_id, EUM_Profiles::META_MANUAL, 0);
        update_post_meta($change_id, EUM_Profiles::META_SKIP_LOG, '0');
        update_post_meta($change_id, EUM_Profiles::META_SKIP_EMAIL, '0');
        if (!empty($transfer_context['is_transfer'])) {
            update_post_meta($change_id, EUM_Profiles::META_TRANSFER_PROTOCOL, '1');
            update_post_meta($change_id, EUM_Profiles::META_TRANSFER_OLD_MANAGER, (int) ($transfer_context['old_manager_user_id'] ?? 0));
            update_post_meta($change_id, EUM_Profiles::META_TRANSFER_NEW_MANAGER, (int) ($transfer_context['new_manager_user_id'] ?? 0));
            if (!empty($transfer_effective['proposed_max_at'])) {
                update_post_meta($change_id, EUM_Profiles::META_TRANSFER_PROPOSED_MAX_AT, (string) $transfer_effective['proposed_max_at']);
            }
        }

        if ($status === 'applied') {
            $profiles->apply_change($change_id);
        }

        return array(
            'change_id' => $change_id,
            'status' => $status,
            'message' => $status === 'pending' ? 'Profile change submitted for approval.' : ($status === 'scheduled' ? 'Profile change scheduled.' : 'Profile change applied.'),
        );
    }

    protected function timecard_entries_for_local_day($user_id, $date) {
        if (!class_exists('EUM_Timeclock')) {
            return new WP_Error('missing_timeclock', 'The Enterprise timeclock plugin is not active.');
        }
        $bounds = $this->local_day_bounds_for_user((int) $user_id, (string) $date);
        if (is_wp_error($bounds)) {
            return $bounds;
        }
        $timeclock = new EUM_Timeclock();
        $entries = $this->invoke_timeclock_nonpublic($timeclock, 'entries_for_user_between', array((int) $user_id, (int) $bounds['start_utc'], (int) $bounds['end_utc']));
        $out = array();
        foreach ((array) $entries as $entry) {
            $entry_id = (int) $entry->ID;
            $start_utc = (int) get_post_meta($entry_id, EUM_Timeclock::META_START_UTC, true);
            $end_utc = (int) get_post_meta($entry_id, EUM_Timeclock::META_END_UTC, true);
            $entry_kind = sanitize_key((string) get_post_meta($entry_id, EUM_Timeclock::META_ENTRY_KIND, true));
            if ($entry_kind === '') {
                $entry_kind = 'shift';
            }
            $hours = ($entry_kind === 'shift' && $end_utc > $start_utc) ? round(($end_utc - $start_utc) / HOUR_IN_SECONDS, 2) : 0.0;
            $job_path_term_id = (int) get_post_meta($entry_id, EUM_Timeclock::META_JOB_PATH_TERM_ID, true);
            $cost_center_term_id = (int) get_post_meta($entry_id, EUM_Timeclock::META_COST_CENTER_TERM_ID, true);
            $out[] = array(
                'entry_id' => $entry_id,
                'entry_kind' => $entry_kind,
                'start_local' => $start_utc > 0 ? $this->format_local_datetime_for_user($start_utc, (int) $user_id, 'Y-m-d H:i') : '',
                'end_local' => $end_utc > 0 ? $this->format_local_datetime_for_user($end_utc, (int) $user_id, 'Y-m-d H:i') : '',
                'hours' => $hours,
                'job_path_term_id' => $job_path_term_id,
                'job_path' => $job_path_term_id > 0 && class_exists('EUM_Profiles') ? (string) EUM_Profiles::term_full_path('eum_job_path', $job_path_term_id, ' / ') : '',
                'task_label' => (string) get_post_meta($entry_id, EUM_Timeclock::META_TASK_LABEL, true),
                'cost_center_term_id' => $cost_center_term_id,
                'cost_center' => $this->portal_cost_center_label($cost_center_term_id),
                'source' => (string) get_post_meta($entry_id, EUM_Timeclock::META_SOURCE, true),
            );
        }
        return $out;
    }

    protected function bot_add_timecard_entry($actor_user_id, $target_user_id, $request) {
        if (!class_exists('EUM_Timeclock')) {
            return new WP_Error('missing_timeclock', 'The Enterprise timeclock plugin is not active.');
        }
        $entry_kind = sanitize_key((string) $request->get_param('entry_kind'));
        if ($entry_kind === 'clock_in') {
            $entry_kind = 'punch_in';
        } elseif ($entry_kind === 'clock_out') {
            $entry_kind = 'punch_out';
        }
        if (!in_array($entry_kind, array('punch_in', 'punch_out'), true)) {
            $entry_kind = 'punch_in';
        }

        $event_local = trim((string) $request->get_param('event_local'));
        $start_local = trim((string) $request->get_param('start_local'));
        $end_local = trim((string) $request->get_param('end_local'));
        $date = sanitize_text_field((string) $request->get_param('date'));
        $time = sanitize_text_field((string) $request->get_param('time'));
        if ($event_local === '' && $date !== '' && $time !== '') {
            $event_local = $date . ' ' . $time;
        }
        if ($start_local === '' && $event_local !== '') {
            $start_local = $event_local;
        }
        if ($entry_kind === 'punch_out' && $end_local === '') {
            $end_local = $event_local !== '' ? $event_local : $start_local;
        }
        $start_utc = $this->local_value_to_utc($start_local, (int) $target_user_id);
        $end_utc = $this->local_value_to_utc($end_local, (int) $target_user_id);
        if ($entry_kind === 'punch_in') {
            if ($start_utc <= 0) {
                return new WP_Error('invalid_start', 'Provide a valid event_local or start_local value.');
            }
            $end_utc = 0;
        } else {
            if ($end_utc <= 0) {
                return new WP_Error('invalid_end', 'Provide a valid event_local or end_local value.');
            }
            $start_utc = $end_utc;
        }

        $timeclock = new EUM_Timeclock();
        $assignment = $this->invoke_timeclock_nonpublic($timeclock, 'default_entry_assignment_for_interval', array((int) $target_user_id, (int) $start_utc, (int) $end_utc));
        if (!is_array($assignment)) {
            $assignment = array();
        }
        if ($request->get_param('job_path_term_id') !== null) {
            $assignment['job_path_term_id'] = max(0, (int) $request->get_param('job_path_term_id'));
        }
        if ($request->get_param('task_label') !== null) {
            $assignment['task_label'] = sanitize_text_field((string) $request->get_param('task_label'));
        }
        if ($request->get_param('cost_center_term_id') !== null) {
            $assignment['cost_center_term_id'] = max(0, (int) $request->get_param('cost_center_term_id'));
        }
        if ($entry_kind === 'punch_in') {
            $this->invoke_timeclock_nonpublic($timeclock, 'maybe_insert_transfer_punch_out', array((int) $target_user_id, (int) $actor_user_id, (int) $start_utc, $assignment));
        }

        $entry_id = $this->invoke_timeclock_nonpublic($timeclock, 'upsert_entry', array(
            0,
            (int) $target_user_id,
            (int) $start_utc,
            (int) $end_utc,
            (int) $actor_user_id,
            $actor_user_id === $target_user_id ? 'discord_edit' : 'discord_manager_edit',
            $assignment,
            $entry_kind
        ));
        if (is_wp_error($entry_id)) {
            return $entry_id;
        }
        return array(
            'entry_id' => (int) $entry_id,
            'entry_kind' => $entry_kind,
            'message' => $entry_kind === 'punch_out' ? 'Punch-out added.' : 'Punch-in added.',
        );
    }

    protected function bot_delete_timecard_entry($target_user_id, $entry_id) {
        $target_user_id = (int) $target_user_id;
        $entry_id = (int) $entry_id;
        if ($entry_id <= 0) {
            return new WP_Error('missing_entry', 'entry_id is required.');
        }
        if (!class_exists('EUM_Timeclock')) {
            return new WP_Error('missing_timeclock', 'The Enterprise timeclock plugin is not active.');
        }
        $entry = get_post($entry_id);
        if (!$entry || $entry->post_type !== EUM_Timeclock::CPT_ENTRY) {
            return new WP_Error('missing_entry', 'Time entry not found.');
        }
        if ((int) get_post_meta($entry_id, EUM_Timeclock::META_USER_ID, true) !== $target_user_id) {
            return new WP_Error('wrong_user', 'That time entry belongs to a different user.');
        }
        wp_delete_post($entry_id, true);
        return 1;
    }

    protected function bot_clear_timecard_day($target_user_id, $date) {
        $entries = $this->timecard_entries_for_local_day((int) $target_user_id, (string) $date);
        if (is_wp_error($entries)) {
            return $entries;
        }
        $deleted = 0;
        foreach ((array) $entries as $entry) {
            $entry_id = isset($entry['entry_id']) ? (int) $entry['entry_id'] : 0;
            if ($entry_id <= 0) {
                continue;
            }
            wp_delete_post($entry_id, true);
            $deleted++;
        }
        return $deleted;
    }

    protected function current_local_date_for_user($user_id) {
        return (new DateTimeImmutable('now', $this->portal_user_timezone((int) $user_id)))->format('Y-m-d');
    }

    protected function local_day_bounds_for_user($user_id, $date) {
        $date = trim((string) $date);
        if ($date === '') {
            $date = $this->current_local_date_for_user((int) $user_id);
        }
        $date = preg_replace('/[^0-9\-]/', '', (string) $date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return new WP_Error('invalid_date', 'Use a valid date in YYYY-MM-DD format.');
        }
        $tz = $this->portal_user_timezone((int) $user_id);
        $start_local = new DateTimeImmutable($date . ' 00:00:00', $tz);
        $end_local = $start_local->modify('+1 day');
        return array(
            'start_utc' => $start_local->setTimezone(new DateTimeZone('UTC'))->getTimestamp(),
            'end_utc' => $end_local->setTimezone(new DateTimeZone('UTC'))->getTimestamp(),
            'timezone' => $tz,
        );
    }

    protected function portal_user_timezone($user_id) {
        $tz_string = $user_id > 0 ? (string) get_user_meta((int) $user_id, 'timezone', true) : '';
        if ($tz_string === '') {
            $tz_string = (string) get_option('timezone_string', '');
        }
        if ($tz_string !== '') {
            try {
                return new DateTimeZone($tz_string);
            } catch (Throwable $e) {
            }
        }
        return wp_timezone();
    }

    protected function local_value_to_utc($value, $user_id) {
        $value = trim(str_replace('T', ' ', (string) $value));
        if ($value === '') {
            return 0;
        }
        try {
            $dt = new DateTimeImmutable($value, $this->portal_user_timezone((int) $user_id));
            return $dt->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
        } catch (Throwable $e) {
            return 0;
        }
    }

    protected function format_local_datetime_for_user($utc_ts, $user_id, $format = 'Y-m-d H:i') {
        return (new DateTimeImmutable('@' . (int) $utc_ts))->setTimezone($this->portal_user_timezone((int) $user_id))->format((string) $format);
    }

    protected function portal_cost_center_label($term_id) {
        $term_id = (int) $term_id;
        if ($term_id <= 0) {
            return '';
        }
        if (class_exists('EUM_Finance') && method_exists('EUM_Finance', 'labor_paycode_option_label')) {
            return (string) EUM_Finance::labor_paycode_option_label($term_id);
        }
        if (class_exists('EUM_Finance') && method_exists('EUM_Finance', 'finance_path_option_label')) {
            return (string) EUM_Finance::finance_path_option_label($term_id);
        }
        return '';
    }

    protected function run_bot_automation_cycle() {
        $queue = $this->pull_sync_queue();
        $queue_result = $this->process_sync_queue($queue);
        $cron = $this->run_due_cron_tasks();
        $schedule = $this->run_schedule_automation_tasks();
        $this->run_dynamic_role_sync();
        $this->run_automation_pass();
        return array(
            'queue_user_count' => (int) $queue_result['queued_user_count'],
            'queue_contexts' => $queue_result['contexts'],
            'queue_full_sync_requested' => !empty($queue_result['full_sync_requested']),
            'queue_full_sync_ran' => !empty($queue_result['full_sync_ran']),
            'queue_synced_user_ids' => $queue_result['synced_user_ids'],
            'queue_failed_user_ids' => $queue_result['failed_user_ids'],
            'cron' => $cron,
            'schedule' => $schedule,
            'role_sync' => array('full_sync' => true),
        );
    }

    protected function run_due_cron_tasks() {
        $result = array(
            'wp_cron' => false,
            'spawn_cron' => false,
        );
        if (function_exists('_wp_cron')) {
            _wp_cron();
            $result['wp_cron'] = true;
        } elseif (function_exists('wp_cron')) {
            wp_cron();
            $result['wp_cron'] = true;
        }
        if (function_exists('spawn_cron')) {
            spawn_cron(time());
            $result['spawn_cron'] = true;
        }
        return $result;
    }

    protected function run_schedule_automation_tasks() {
        if (!class_exists('EUM_Scheduling')) {
            return array();
        }
        $scheduling = new EUM_Scheduling();
        return array(
            'template_catchup_created' => (int) $scheduling->maybe_apply_template_catchup_for_current_and_next_week(),
            'next_week_templates_created' => (int) $scheduling->maybe_apply_next_week_templates_on_monday(),
            'next_week_published' => (int) $scheduling->maybe_auto_publish_next_week_on_thursday(),
        );
    }

    public function handle_portal_notification_created($notification_id, $user_id, $payload = array()) {
        $notification_id = (int) $notification_id;
        $user_id = (int) $user_id;
        $payload = is_array($payload) ? $payload : array();
        if ($notification_id <= 0 || $user_id <= 0 || empty($payload['deliver_discord'])) {
            return;
        }
        $title = trim((string) ($payload['title'] ?? ''));
        $body = trim((string) ($payload['body'] ?? ''));
        $action_url = trim((string) ($payload['action_url'] ?? ''));
        $message = $title;
        if ($body !== '') {
            $message .= "\n" . $body;
        }
        if ($action_url !== '') {
            $message .= "\n" . $action_url;
        }
        if ($message === '') {
            return;
        }
        $this->queue_portal_user_dm($user_id, $message, (int) ($payload['actor_user_id'] ?? 0), 'portal_notification');
    }

    public function handle_portal_activity_logged($message, $context = array()) {
        $formatted = $this->format_activity_message('portal', array_merge(
            is_array($context) ? $context : array(),
            array('summary' => sanitize_text_field((string) $message))
        ));
        $this->log_configured_activity_message($formatted);
    }

    protected function log_configured_activity_message($message) {
        $message = trim((string) $message);
        if ($message === '') {
            return false;
        }
        $channel_id = trim((string) ($this->settings()['activity_log_channel_id'] ?? ''));
        if ($channel_id === '') {
            return false;
        }
        return $this->post_channel_message($channel_id, $this->truncate_for_discord($message));
    }

    protected function format_activity_message($event_type, array $context = array()) {
        $summary = sanitize_text_field((string) ($context['summary'] ?? ucfirst(str_replace('_', ' ', (string) $event_type))));
        $lines = array(
            '[' . wp_date('Y-m-d H:i:s', time(), wp_timezone()) . '] ' . $summary,
        );
        $actor_user_id = (int) ($context['actor_user_id'] ?? 0);
        $user_id = (int) ($context['user_id'] ?? 0);
        if ($actor_user_id > 0) {
            $lines[] = 'Actor: ' . $this->portal_user_label($actor_user_id);
        }
        if ($user_id > 0) {
            $lines[] = 'Target: ' . $this->portal_user_label($user_id);
        }
        $details = isset($context['details']) && is_array($context['details']) ? $context['details'] : array();
        foreach ($details as $key => $value) {
            if (is_array($value)) {
                $value = wp_json_encode($value);
            }
            if (is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            }
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $lines[] = ucfirst(str_replace('_', ' ', sanitize_key((string) $key))) . ': ' . $value;
        }
        return implode("\n", array_filter($lines));
    }

    protected function truncate_for_discord($message, $max_length = 1900) {
        $message = trim((string) $message);
        if (strlen($message) <= $max_length) {
            return $message;
        }
        return substr($message, 0, $max_length - 18) . "\n...truncated.";
    }

    protected function submit_leave_request_from_bot($actor_user_id, $target_user_id, $request) {
        if (!class_exists('EUM_Workforce_Lifecycle')) {
            return new WP_Error('missing_workforce', 'The workforce tools are not active.');
        }
        $workflow = new EUM_Workforce_Lifecycle();
        $leave_type = $this->resolve_bot_leave_type((string) $request->get_param('leave_type'));
        if (empty($leave_type)) {
            return new WP_Error('invalid_leave_type', 'Choose a valid leave type key or label.');
        }
        if (!(bool) $this->invoke_nonpublic_method($workflow, 'can_request_leave_type', array((int) $actor_user_id, (int) $target_user_id, $leave_type))) {
            return new WP_Error('leave_not_allowed', 'You cannot request that leave type for that user.');
        }
        $start_date = $this->sanitize_bot_date((string) $request->get_param('start_date'));
        $return_date = $this->sanitize_bot_date((string) $request->get_param('return_date'));
        $reason = sanitize_text_field((string) $request->get_param('reason'));
        $notes = sanitize_textarea_field((string) $request->get_param('notes'));
        if ($start_date === '') {
            return new WP_Error('missing_start_date', 'start_date must use YYYY-MM-DD.');
        }
        if (!empty($leave_type['return_required']) && $return_date === '') {
            return new WP_Error('missing_return_date', 'That leave type requires a return_date.');
        }
        $date_error = (string) $this->invoke_nonpublic_method($workflow, 'validate_leave_dates', array($start_date, $return_date, $leave_type));
        if ($date_error !== '') {
            return new WP_Error('invalid_leave_dates', $date_error);
        }
        $hours = (float) EUM_Workforce_Lifecycle::requested_leave_hours($start_date, $return_date);
        if (!empty($leave_type['uses_accrual'])) {
            $settings = (array) EUM_Workforce_Lifecycle::get_settings();
            $accrual = (array) ($settings['vacation_accrual'] ?? array());
            $balance = (float) EUM_Workforce_Lifecycle::vacation_balance_hours((int) $target_user_id);
            if (empty($accrual['allow_negative']) && $hours > $balance) {
                return new WP_Error('insufficient_balance', 'That request exceeds the current vacation balance.');
            }
        }
        $approver_ids = array_values(array_filter(array_map('intval', (array) $this->invoke_nonpublic_method($workflow, 'approver_ids_for_leave', array((int) $actor_user_id, (int) $target_user_id, $leave_type)))));
        $target = get_userdata((int) $target_user_id);
        $post_id = wp_insert_post(array(
            'post_type' => EUM_Workforce_Lifecycle::CPT_LEAVE,
            'post_status' => 'publish',
            'post_author' => (int) $actor_user_id,
            'post_title' => 'Leave - ' . ($target ? ($target->display_name ?: $target->user_login) : ('User #' . (int) $target_user_id)) . ' - ' . (string) ($leave_type['label'] ?? 'Leave'),
        ), true);
        if (is_wp_error($post_id) || (int) $post_id <= 0) {
            return new WP_Error('leave_save_failed', 'Could not save the leave request.');
        }
        $post_id = (int) $post_id;
        update_post_meta($post_id, EUM_Workforce_Lifecycle::META_TARGET_USER, (int) $target_user_id);
        update_post_meta($post_id, EUM_Workforce_Lifecycle::META_ACTOR_USER, (int) $actor_user_id);
        update_post_meta($post_id, EUM_Workforce_Lifecycle::META_TYPE, sanitize_key((string) ($leave_type['key'] ?? '')));
        update_post_meta($post_id, EUM_Workforce_Lifecycle::META_STATUS, 'pending');
        update_post_meta($post_id, EUM_Workforce_Lifecycle::META_START_DATE, $start_date);
        update_post_meta($post_id, EUM_Workforce_Lifecycle::META_RETURN_DATE, $return_date);
        update_post_meta($post_id, EUM_Workforce_Lifecycle::META_HOURS, $hours);
        update_post_meta($post_id, EUM_Workforce_Lifecycle::META_REASON, $reason);
        update_post_meta($post_id, EUM_Workforce_Lifecycle::META_NOTES, $notes);
        update_post_meta($post_id, EUM_Workforce_Lifecycle::META_APPROVER_IDS, $approver_ids);
        update_post_meta($post_id, EUM_Workforce_Lifecycle::META_RETAINED_ACCESS, (array) ($leave_type['retained_access'] ?? array()));
        do_action('eum_leave_request_created', $post_id, (int) $target_user_id, (int) $actor_user_id);
        return array(
            'request_id' => $post_id,
            'status' => 'pending',
            'message' => 'Leave request submitted for approval.',
        );
    }

    protected function submit_availability_request_from_bot($actor_user_id, $target_user_id, $request) {
        if (!class_exists('EUM_Workforce_Lifecycle')) {
            return new WP_Error('missing_workforce', 'The workforce tools are not active.');
        }
        $workflow = new EUM_Workforce_Lifecycle();
        if (!(bool) $this->invoke_nonpublic_method($workflow, 'can_request_availability_for_user', array((int) $actor_user_id, (int) $target_user_id))) {
            return new WP_Error('availability_not_allowed', 'You cannot update availability for that user.');
        }
        $scope = sanitize_key((string) $request->get_param('scope'));
        if (!in_array($scope, array('default', 'week'), true)) {
            $scope = 'week';
        }
        $as_of_date = $this->sanitize_bot_date((string) $request->get_param('as_of_date'));
        if ($as_of_date === '') {
            $as_of_date = current_time('Y-m-d');
        }
        $week_start = EUM_Workforce_Lifecycle::week_start_for_date((string) $request->get_param('week_start'));
        $segments = $this->parse_bot_availability_segments((string) $request->get_param('segments'));
        if (empty($segments)) {
            return new WP_Error('invalid_segments', 'Segments must use a format like "Mon 09:00-17:00; Tue 10:00-14:00".');
        }
        $notes = sanitize_textarea_field((string) $request->get_param('notes'));
        $approver_ids = array_values(array_filter(array_map('intval', (array) $this->invoke_nonpublic_method($workflow, 'approver_ids_for_availability', array((int) $target_user_id, array((int) $actor_user_id, (int) $target_user_id))))));
        if (empty($approver_ids)) {
            return new WP_Error('missing_approvers', 'No managers are available to approve that availability change.');
        }
        $post_id = wp_insert_post(array(
            'post_type' => EUM_Workforce_Lifecycle::CPT_AVAILABILITY,
            'post_status' => 'publish',
            'post_author' => (int) $actor_user_id,
            'post_title' => 'Availability: ' . $this->portal_user_label((int) $target_user_id) . ' - ' . ($scope === 'default' ? 'Default as of ' . $as_of_date : 'Week of ' . $week_start),
        ), true);
        if (is_wp_error($post_id) || (int) $post_id <= 0) {
            return new WP_Error('availability_save_failed', 'Availability request could not be saved.');
        }
        $request_id = (int) $post_id;
        update_post_meta($request_id, EUM_Workforce_Lifecycle::AV_META_TARGET_USER, (int) $target_user_id);
        update_post_meta($request_id, EUM_Workforce_Lifecycle::AV_META_ACTOR_USER, (int) $actor_user_id);
        update_post_meta($request_id, EUM_Workforce_Lifecycle::AV_META_STATUS, 'pending');
        update_post_meta($request_id, EUM_Workforce_Lifecycle::AV_META_SCOPE, $scope);
        update_post_meta($request_id, EUM_Workforce_Lifecycle::AV_META_AS_OF_DATE, $as_of_date);
        update_post_meta($request_id, EUM_Workforce_Lifecycle::AV_META_WEEK_START, $week_start);
        update_post_meta($request_id, EUM_Workforce_Lifecycle::AV_META_SEGMENTS, $segments);
        update_post_meta($request_id, EUM_Workforce_Lifecycle::AV_META_NOTES, $notes);
        update_post_meta($request_id, EUM_Workforce_Lifecycle::AV_META_APPROVER_IDS, $approver_ids);
        do_action('eum_availability_request_created', $request_id, (int) $target_user_id, (int) $actor_user_id, 'pending');
        return array(
            'request_id' => $request_id,
            'status' => 'pending',
            'message' => 'Availability request sent to manager.',
        );
    }

    protected function resolve_bot_leave_type($input) {
        if (!class_exists('EUM_Workforce_Lifecycle')) {
            return array();
        }
        $needle = trim((string) $input);
        $key = sanitize_key($needle);
        foreach ((array) EUM_Workforce_Lifecycle::leave_types() as $type) {
            if (!is_array($type)) {
                continue;
            }
            $type_key = sanitize_key((string) ($type['key'] ?? ''));
            $label_key = sanitize_key(str_replace(' ', '_', strtolower((string) ($type['label'] ?? ''))));
            if ($type_key === $key || $label_key === $key || strtolower((string) ($type['label'] ?? '')) === strtolower($needle)) {
                return $type;
            }
        }
        if ($key === 'vacation') {
            return (array) EUM_Workforce_Lifecycle::leave_type('vacation');
        }
        return array();
    }

    protected function parse_bot_availability_segments($raw) {
        $raw = str_replace(array("\r\n", "\r"), "\n", (string) $raw);
        $parts = preg_split('/[;\n]+/', $raw) ?: array();
        $day_map = array(
            'mon' => 1, 'monday' => 1,
            'tue' => 2, 'tues' => 2, 'tuesday' => 2,
            'wed' => 3, 'wednesday' => 3,
            'thu' => 4, 'thur' => 4, 'thurs' => 4, 'thursday' => 4,
            'fri' => 5, 'friday' => 5,
            'sat' => 6, 'saturday' => 6,
            'sun' => 7, 'sunday' => 7,
        );
        $segments = array();
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            if (!preg_match('/^([A-Za-z]+|\d)\s+([01]\d|2[0-3]):([0-5]\d)\s*-\s*([01]\d|2[0-3]):([0-5]\d)$/', $part, $matches)) {
                continue;
            }
            $day_token = strtolower((string) $matches[1]);
            $day = ctype_digit($day_token) ? (int) $day_token : (int) ($day_map[$day_token] ?? 0);
            $start = $matches[2] . ':' . $matches[3];
            $end = $matches[4] . ':' . $matches[5];
            if ($day < 1 || $day > 7 || $end <= $start) {
                continue;
            }
            $segments[] = array('day' => $day, 'start' => $start, 'end' => $end);
        }
        usort($segments, static function ($a, $b) {
            $day_cmp = ((int) $a['day']) <=> ((int) $b['day']);
            return $day_cmp !== 0 ? $day_cmp : strcmp((string) $a['start'], (string) $b['start']);
        });
        return $segments;
    }

    protected function sanitize_bot_date($value) {
        $value = trim((string) $value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    public function run_automation_pass() {
        $this->process_dm_queue();
        $this->notify_pending_attention_items();
        $this->remind_inactive_portal_users();
    }

    public function handle_leave_attention_created($leave_id, $target_user_id = 0, $actor_user_id = 0) {
        $leave_id = (int) $leave_id;
        if ($leave_id <= 0 || !class_exists('EUM_Workforce_Lifecycle')) {
            return;
        }
        if (class_exists('EUM_Notification_Center')) {
            return;
        }
        $status = (string) get_post_meta($leave_id, EUM_Workforce_Lifecycle::META_STATUS, true);
        if ($status !== 'pending') {
            return;
        }
        $resolved_target_user_id = (int) get_post_meta($leave_id, EUM_Workforce_Lifecycle::META_TARGET_USER, true);
        $type = EUM_Workforce_Lifecycle::leave_type((string) get_post_meta($leave_id, EUM_Workforce_Lifecycle::META_TYPE, true));
        $approver_ids = array_values(array_filter(array_map('intval', (array) get_post_meta($leave_id, EUM_Workforce_Lifecycle::META_APPROVER_IDS, true))));
        $message = 'Approval needed in the Enterprise portal:' . "\n"
            . 'Leave request: ' . (string) ($type['label'] ?? 'Leave') . "\n"
            . 'Target: ' . $this->portal_user_label($resolved_target_user_id) . "\n"
            . home_url('/');
        $this->notify_users_for_attention_item($leave_id, $approver_ids, $message, 'leave_request');
    }

    public function handle_availability_attention_created($request_id, $target_user_id = 0, $actor_user_id = 0, $status = 'pending') {
        $request_id = (int) $request_id;
        if ($request_id <= 0 || !class_exists('EUM_Workforce_Lifecycle') || (string) $status !== 'pending') {
            return;
        }
        if (class_exists('EUM_Notification_Center')) {
            return;
        }
        $resolved_target_user_id = (int) get_post_meta($request_id, EUM_Workforce_Lifecycle::AV_META_TARGET_USER, true);
        $approver_ids = array_values(array_filter(array_map('intval', (array) get_post_meta($request_id, EUM_Workforce_Lifecycle::AV_META_APPROVER_IDS, true))));
        $message = 'Approval needed in the Enterprise portal:' . "\n"
            . 'Availability update for: ' . $this->portal_user_label($resolved_target_user_id) . "\n"
            . home_url('/');
        $this->notify_users_for_attention_item($request_id, $approver_ids, $message, 'availability_request');
    }

    public function handle_attention_status_changed($item_id, $target_user_id = 0, $status = '', $actor_user_id = 0) {
        $item_id = (int) $item_id;
        if ($item_id <= 0 || (string) $status === 'pending') {
            return;
        }
        delete_post_meta($item_id, self::META_ATTENTION_NOTIFIED_USER_IDS);
    }

    protected function queue_portal_user_dm($user_id, $message, $actor_user_id = 0, $context = 'automation') {
        $user_id = (int) $user_id;
        $actor_user_id = (int) $actor_user_id;
        $message = trim((string) $message);
        if ($user_id <= 0 || $message === '') {
            return new WP_Error('invalid_dm', 'A valid user and message are required.');
        }
        $discord_user_id = trim((string) get_user_meta($user_id, 'edb_discord_user_id', true));
        if ($discord_user_id === '') {
            return new WP_Error('missing_discord', 'That user does not have a linked Discord account.');
        }
        $not_before = $this->next_allowed_dm_timestamp_for_user($user_id);
        if ($not_before <= time()) {
            $sent = $this->send_dm($discord_user_id, $message, $actor_user_id);
            if (!$sent) {
                return new WP_Error('send_failed', 'Discord rejected the direct message.');
            }
            return array(
                'status' => 'sent',
                'not_before' => $not_before,
            );
        }
        $queue = get_option(self::OPTION_DM_QUEUE, array());
        $queue = is_array($queue) ? $queue : array();
        $queue[] = array(
            'id' => wp_generate_uuid4(),
            'user_id' => $user_id,
            'discord_user_id' => $discord_user_id,
            'message' => $message,
            'actor_user_id' => $actor_user_id,
            'context' => sanitize_key((string) $context),
            'not_before' => $not_before,
            'attempts' => 0,
            'created_at' => time(),
        );
        update_option(self::OPTION_DM_QUEUE, $queue, false);
        return array(
            'status' => 'queued',
            'not_before' => $not_before,
        );
    }

    protected function process_dm_queue() {
        $queue = get_option(self::OPTION_DM_QUEUE, array());
        if (!is_array($queue) || empty($queue)) {
            return;
        }
        $now = time();
        $remaining = array();
        foreach ($queue as $item) {
            if (!is_array($item)) {
                continue;
            }
            $discord_user_id = trim((string) ($item['discord_user_id'] ?? ''));
            $message = (string) ($item['message'] ?? '');
            $actor_user_id = (int) ($item['actor_user_id'] ?? 0);
            $not_before = (int) ($item['not_before'] ?? 0);
            $attempts = (int) ($item['attempts'] ?? 0);
            if ($discord_user_id === '' || $message === '') {
                continue;
            }
            if ($not_before > $now) {
                $remaining[] = $item;
                continue;
            }
            if ($this->send_dm($discord_user_id, $message, $actor_user_id)) {
                continue;
            }
            $attempts++;
            if ($attempts < 3) {
                $item['attempts'] = $attempts;
                $item['not_before'] = $now + (15 * MINUTE_IN_SECONDS);
                $remaining[] = $item;
            }
        }
        update_option(self::OPTION_DM_QUEUE, array_values($remaining), false);
    }

    protected function next_allowed_dm_timestamp_for_user($user_id, $base_timestamp = 0) {
        $base_timestamp = (int) $base_timestamp;
        if ($base_timestamp <= 0) {
            $base_timestamp = time();
        }
        $tz = $this->portal_user_timezone((int) $user_id);
        $local = (new DateTimeImmutable('@' . $base_timestamp))->setTimezone($tz);
        $minutes = ((int) $local->format('H') * 60) + (int) $local->format('i');
        if ($minutes >= ((19 * 60) + 30)) {
            $next_local = $local->setTime(6, 0, 0)->modify('+1 day');
            return $next_local->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
        }
        if ($minutes < (6 * 60)) {
            $next_local = $local->setTime(6, 0, 0);
            return $next_local->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
        }
        return $base_timestamp;
    }

    protected function remind_inactive_portal_users() {
        $users = get_users(array('number' => 0, 'orderby' => 'ID', 'order' => 'ASC'));
        $now = current_time('timestamp');
        $threshold = $now - (5 * DAY_IN_SECONDS);
        foreach ((array) $users as $user) {
            if (!$user instanceof WP_User) {
                continue;
            }
            if (class_exists('EUM_Personnel') && EUM_Personnel::is_terminated((int) $user->ID)) {
                continue;
            }
            $last_login_raw = (string) get_user_meta($user->ID, self::USER_META_LAST_PORTAL_LOGIN_AT, true);
            if ($last_login_raw === '') {
                $last_login_raw = (string) $user->user_registered;
            }
            $last_login_ts = $this->mysql_local_to_timestamp($last_login_raw);
            if ($last_login_ts > $threshold) {
                continue;
            }

            $notice = 'It has been 5 days since your last Enterprise portal sign-in. Please sign in when you can: ' . home_url('/');
            $discord_id = trim((string) get_user_meta((int) $user->ID, 'edb_discord_user_id', true));
            $last_discord_reminder = $this->mysql_local_to_timestamp((string) get_user_meta((int) $user->ID, self::USER_META_LAST_PORTAL_LOGIN_REMINDER_AT, true));
            if ($discord_id !== '' && $last_discord_reminder <= ($now - 5 * DAY_IN_SECONDS)) {
                $result = $this->queue_portal_user_dm((int) $user->ID, $notice, 0, 'login_reminder');
                if (!is_wp_error($result)) {
                    update_user_meta((int) $user->ID, self::USER_META_LAST_PORTAL_LOGIN_REMINDER_AT, current_time('mysql'));
                }
            }

            $last_email_reminder = $this->mysql_local_to_timestamp((string) get_user_meta((int) $user->ID, self::USER_META_LAST_PORTAL_EMAIL_REMINDER_AT, true));
            if ($last_email_reminder <= ($now - 5 * DAY_IN_SECONDS) && class_exists('EUM_Fields')) {
                $fields = (array) get_user_meta((int) $user->ID, EUM_Fields::USER_META_KEY, true);
                $personal_email = sanitize_email((string) ($fields['personal_email'] ?? ''));
                if ($personal_email !== '' && is_email($personal_email)) {
                    $subject = 'Enterprise portal sign-in reminder';
                    $body = '<p>' . esc_html($notice) . '</p><p><a href="' . esc_url(home_url('/')) . '">Open the Enterprise portal</a></p>';
                    if (wp_mail($personal_email, $subject, $body, array('Content-Type: text/html; charset=UTF-8'))) {
                        update_user_meta((int) $user->ID, self::USER_META_LAST_PORTAL_EMAIL_REMINDER_AT, current_time('mysql'));
                    }
                }
            }
        }
    }

    protected function notify_pending_attention_items() {
        if (class_exists('EUM_Notification_Center')) {
            return;
        }
        if (class_exists('EUM_Profiles')) {
            $items = get_posts(array(
                'post_type' => EUM_Profiles::CPT_CHANGE,
                'post_status' => 'publish',
                'numberposts' => 100,
                'meta_query' => array(
                    array(
                        'key' => EUM_Profiles::META_STATUS,
                        'value' => 'pending',
                    ),
                ),
            ));
            foreach ((array) $items as $item) {
                $post_id = (int) $item->ID;
                $target_user_id = (int) get_post_meta($post_id, EUM_Profiles::META_TARGET_USER, true);
                $approver_ids = array_values(array_filter(array_map('intval', (array) get_post_meta($post_id, EUM_Profiles::META_APPROVER_IDS, true))));
                $message = 'Approval needed in the Enterprise portal:' . "\n"
                    . 'Profile change: ' . (string) $item->post_title . "\n"
                    . 'Target: ' . $this->portal_user_label($target_user_id) . "\n"
                    . home_url('/');
                $this->notify_users_for_attention_item($post_id, $approver_ids, $message, 'profile_change');
            }
        }

        if (class_exists('EUM_Workforce_Lifecycle')) {
            $leave_items = get_posts(array(
                'post_type' => EUM_Workforce_Lifecycle::CPT_LEAVE,
                'post_status' => 'publish',
                'numberposts' => 100,
                'meta_query' => array(
                    array(
                        'key' => EUM_Workforce_Lifecycle::META_STATUS,
                        'value' => 'pending',
                    ),
                ),
            ));
            foreach ((array) $leave_items as $item) {
                $post_id = (int) $item->ID;
                $target_user_id = (int) get_post_meta($post_id, EUM_Workforce_Lifecycle::META_TARGET_USER, true);
                $type = EUM_Workforce_Lifecycle::leave_type((string) get_post_meta($post_id, EUM_Workforce_Lifecycle::META_TYPE, true));
                $approver_ids = array_values(array_filter(array_map('intval', (array) get_post_meta($post_id, EUM_Workforce_Lifecycle::META_APPROVER_IDS, true))));
                $message = 'Approval needed in the Enterprise portal:' . "\n"
                    . 'Leave request: ' . (string) ($type['label'] ?? 'Leave') . "\n"
                    . 'Target: ' . $this->portal_user_label($target_user_id) . "\n"
                    . home_url('/');
                $this->notify_users_for_attention_item($post_id, $approver_ids, $message, 'leave_request');
            }

            $availability_items = get_posts(array(
                'post_type' => EUM_Workforce_Lifecycle::CPT_AVAILABILITY,
                'post_status' => 'publish',
                'numberposts' => 100,
                'meta_query' => array(
                    array(
                        'key' => EUM_Workforce_Lifecycle::AV_META_STATUS,
                        'value' => 'pending',
                    ),
                ),
            ));
            foreach ((array) $availability_items as $item) {
                $post_id = (int) $item->ID;
                $target_user_id = (int) get_post_meta($post_id, EUM_Workforce_Lifecycle::AV_META_TARGET_USER, true);
                $approver_ids = array_values(array_filter(array_map('intval', (array) get_post_meta($post_id, EUM_Workforce_Lifecycle::AV_META_APPROVER_IDS, true))));
                $message = 'Approval needed in the Enterprise portal:' . "\n"
                    . 'Availability update for: ' . $this->portal_user_label($target_user_id) . "\n"
                    . home_url('/');
                $this->notify_users_for_attention_item($post_id, $approver_ids, $message, 'availability_request');
            }
        }
    }

    protected function notify_users_for_attention_item($post_id, array $user_ids, $message, $context) {
        $post_id = (int) $post_id;
        $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids), static function ($user_id) {
            return $user_id > 0;
        })));
        if ($post_id <= 0 || empty($user_ids)) {
            return;
        }
        $already = get_post_meta($post_id, self::META_ATTENTION_NOTIFIED_USER_IDS, true);
        $already = is_array($already) ? array_map('intval', $already) : array();
        foreach ($user_ids as $user_id) {
            if (in_array($user_id, $already, true)) {
                continue;
            }
            $result = $this->queue_portal_user_dm($user_id, (string) $message, 0, (string) $context);
            if (!is_wp_error($result)) {
                $already[] = $user_id;
            }
        }
        update_post_meta($post_id, self::META_ATTENTION_NOTIFIED_USER_IDS, array_values(array_unique(array_map('intval', $already))));
    }

    protected function mysql_local_to_timestamp($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return 0;
        }
        try {
            $dt = new DateTimeImmutable($value, wp_timezone());
            return $dt->getTimestamp();
        } catch (Throwable $e) {
            return 0;
        }
    }

    protected function safe_run_single_user_sync($user_id) {
        $user_id = absint($user_id);
        if ($user_id <= 0) {
            return false;
        }
        try {
            if (method_exists($this, 'run_single_user_sync')) {
                $this->run_single_user_sync($user_id);
                return true;
            }
            if (method_exists($this, 'refresh_user_guild_cache')) {
                $this->refresh_user_guild_cache($user_id);
            }
            if (method_exists($this, 'sync_user_roles')) {
                $this->sync_user_roles($user_id);
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }


    protected function invoke_timeclock_nonpublic($object, $method_name, array $args = array()) {
        return $this->invoke_nonpublic_method($object, $method_name, $args);
    }

    protected function invoke_nonpublic_method($object, $method_name, array $args = array()) {
        $ref = new ReflectionMethod($object, $method_name);
        if (method_exists($ref, 'setAccessible')) {
            $ref->setAccessible(true);
        }
        return $ref->invokeArgs($object, $args);
    }

    protected function invoke_nonpublic_static($class_name, $method_name, array $args = array()) {
        $ref = new ReflectionMethod((string) $class_name, (string) $method_name);
        if (method_exists($ref, 'setAccessible')) {
            $ref->setAccessible(true);
        }
        return $ref->invokeArgs(null, $args);
    }

}
