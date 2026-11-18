# Classes

PSR-4-style classes under the `Opng\` namespace live here. `src/bootstrap.php`
maps `Opng\Example\Thing` to `src/classes/Example/Thing.php` and loads the file
on first use. Existing shared helpers remain plain functions in
`src/includes/`; new classes can be introduced without adding another require.
