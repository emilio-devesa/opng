<?php
// includes/layout_footer.php
// Controles comunes (modo oscuro + selector de idioma) y navegación de sesión.
// Variables: $base (prefijo de rutas, por defecto '').
if (!isset($base)) { $base = ''; }
$logged_in = isset($_SESSION['user_id']);
?>
        <br>
        <button id="theme-toggle" data-i18n="dark_mode">Dark Mode</button>
        <script src="<?php echo $base; ?>assets/js/dark-mode.js" data-base="<?php echo $base; ?>"></script>
        <br><br>
        <select id="language-selector">
            <option value="en">🇬🇧 English</option>
            <option value="es">🇪🇸 Español</option>
            <option value="de">🇩🇪 Deutsch</option>
            <option value="fr">🇫🇷 Français</option>
            <option value="pt">🇵🇹 Português</option>
            <option value="zh">🇨🇳 中文</option>
        </select>
        <script src="<?php echo $base; ?>assets/js/language.js" data-base="<?php echo $base; ?>"></script>
    </div>
    <footer>
        <nav>
            <ul>
                <?php if ($logged_in): ?>
                    <li><a data-i18n="logout" href="<?php echo $base; ?>auth/logout.php">Logout</a></li>
                <?php else: ?>
                    <li><a data-i18n="login" href="<?php echo $base; ?>auth/login.php">Login</a></li>
                    <li><a data-i18n="register" href="<?php echo $base; ?>auth/register.php">Register</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </footer>
</body>
</html>
