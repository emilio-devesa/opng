<?php
require(__DIR__ . '/includes/session.php');
session_start_secured();

$flash_message = isset($_GET['msg']) && is_string($_GET['msg']) ? $_GET['msg'] : '';
$message_key = match ($flash_message) {
    'Invalid ID' => 'error_invalid_id',
    'Database connection error.' => 'error_database_unavailable',
    'Error: No se pudo conectar a la base de datos.' => 'error_database_unavailable',
    'Entry not found' => 'error_entry_not_found',
    'Request method not allowed.' => 'error_method_not_allowed',
    'Error: invalid CSRF token.' => 'error_csrf',
    'Invalid paste data.', 'The language is required.' => 'error_invalid_paste',
    'Too many pastes created or the service is temporarily unavailable.' => 'error_paste_rate_limit',
    'Error saving the paste.' => 'error_save_paste',
    'You must be signed in to edit a paste.' => 'error_sign_in_to_edit',
    'Database operation failed.' => 'error_database_operation',
    'You don\'t have permission to edit this paste.',
    'You don\'t have permission to delete this paste' => 'error_permission',
    'Error updating the paste.', 'Error updating the paste' => 'error_update_paste',
    'Invalid email or password.' => 'error_invalid_credentials',
    'Authentication service is temporarily unavailable.' => 'error_auth_unavailable',
    'Too many login attempts. Please try again in 10 minutes.' => 'error_login_rate_limit',
    'Invalid registration data.' => 'error_invalid_registration',
    'Please check the username and email limits.' => 'error_invalid_registration',
    'Choose a password between 12 and 72 bytes long.' => 'password_policy',
    'Too many registration attempts. Please try again later.' => 'error_registration_rate_limit',
    'Incorrect CAPTCHA. Try again.' => 'captcha_incorrect',
    'This email is already registered.' => 'error_email_registered',
    'Registration error.' => 'error_registration',
    'Registration service is temporarily unavailable.' => 'error_auth_unavailable',
    'Unauthorized access' => 'error_permission',
    'Access denied. You don\'t have permission to view this page.' => 'error_permission',
    'Error preparing the query.' => 'error_database_operation',
    'Error while emptying the database' => 'error_empty_pastes',
    'Invalid search query.' => 'error_invalid_search',
    'Invalid page number.' => 'error_invalid_page',
    'Language configuration is unavailable.' => 'error_language_config',
    default => str_starts_with($flash_message, 'Error: The selected language')
        ? 'error_invalid_language'
        : ($flash_message === '' ? 'generic_error' : ''),
};
$message = $flash_message !== '' ? $flash_message : 'An error occurred.';
$lang_attr = 'en';
$page_title = 'Error';
$page_title_i18n = 'error_title';
$base = '';
require(__DIR__ . '/includes/layout_header.php');
?>
    <section class="error-page">
        <h2 data-i18n="error_title">Error</h2>
        <p role="alert"<?php echo $message_key !== '' ? ' data-i18n="' . htmlspecialchars($message_key, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </p>
        <p><a href="index.php" data-i18n="return">Return to Home</a></p>
    </section>
<?php require(__DIR__ . '/includes/layout_footer.php'); ?>
