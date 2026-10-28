<?php
/**
 * Seed de desarrollo para Open Pastebin NG (v0.8).
 *
 * Crea usuarios normales, administradores, 60 pastes y 12 respuestas para
 * probar la paginacion, la busqueda y los permisos por rol.
 *
 * Uso (desde la raiz del repo):
 *   php tools/seed.php
 *
 * - Solo funciona por CLI (no accesible por HTTP).
 * - Es re-ejecutable: antes de insertar borra unicamente sus propios datos
 *   (entries con id LIKE 'seed%' y usuarios @example.test).
 * - Los usuarios creados usan la contrasena: Password123!
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require __DIR__ . '/../src/config.php';

$db = new mysqli($mysql_server, $mysql_username, $mysql_password, $mysql_dbname);
if ($db->connect_error) {
    fwrite(STDERR, "ERROR MySQL: " . $db->connect_error . "\n");
    exit(1);
}
$db->set_charset('utf8mb4');

// ---------------------------------------------------------------------------
// Limpieza de seeds anteriores (solo datos con prefijo seed / @example.test)
// ---------------------------------------------------------------------------
$db->query("DELETE FROM entries WHERE id LIKE 'seed%'");
$db->query("DELETE FROM users WHERE email LIKE '%@example.test'");

// ---------------------------------------------------------------------------
// Usuarios
// ---------------------------------------------------------------------------
$password_hash = password_hash('Password123!', PASSWORD_DEFAULT);

$users = [
    // username, email, role
    ['lucia_dev',   'lucia@example.test',    'user'],
    ['mario_ops',   'mario@example.test',    'user'],
    ['ana_code',    'ana@example.test',      'user'],
    ['javier_ml',   'javier@example.test',   'user'],
    ['sofia_web',   'sofia@example.test',    'user'],
    ['diego_data',  'diego@example.test',    'user'],
    ['elena_sec',   'elena@example.test',    'user'],
    ['pablo_sys',   'pablo@example.test',    'user'],
    ['root_ops',    'root@example.test',     'admin'],
    ['maria_admin', 'maria@example.test',    'admin'],
];

$stmt = $db->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)");
$user_ids = [];
$normal_ids = [];
$admin_ids = [];
foreach ($users as [$name, $email, $role]) {
    $stmt->bind_param('ssss', $name, $email, $password_hash, $role);
    $stmt->execute();
    $user_ids[] = $db->insert_id;
    if ($role === 'admin') {
        $admin_ids[] = $db->insert_id;
    } else {
        $normal_ids[] = $db->insert_id;
    }
}
$stmt->close();

// ---------------------------------------------------------------------------
// Snippets por lenguaje (nowdoc: sin interpolacion de $)
// ---------------------------------------------------------------------------
$snippets = [
    'Plaintext' => [
<<<'EOT'
Checklist para desplegar en produccion:

1. Ejecutar install.php una sola vez.
2. Borrar install.php del servidor.
3. Comprobar que config.php no es accesible por HTTP.
4. Revisar los logs de PHP-FPM.
5. Probar login, creacion y borrado de pastes.
EOT
,
<<<'EOT'
Notas de la reunion de arquitectura

- El listado debe paginar de 50 en 50.
- La busqueda usa FULLTEXT por palabras y la paginacion usa cursor por fecha/ID.
- El editor solo lo ve el autor o un admin.
- Las respuestas se guardan como un paste nuevo con prefijo RE:.

Los indices de listado, cursor, propietario y busqueda se aplican con install.php.
EOT
,
<<<'EOT'
Errores frecuentes al migrar de PHP 7.4

- mysqli deja de aceptar parametros por referencia implicita.
- session.sid_length ya no se usa.
- Las cookies SameSite=Lax rompen algunos flujos cross-site.
- Revisar siempre la version parcheada por los CVEs de octubre.
EOT
,
<<<'EOT'
Apuntes de SQL

SELECT entries.id, users.username
FROM entries
LEFT JOIN users ON entries.user_id = users.id
WHERE MATCH(entries.topic) AGAINST ('+docker*' IN BOOLEAN MODE)
ORDER BY entries.date DESC, entries.id DESC
LIMIT 51;

El LEFT JOIN es importante para no perder los pastes anonimos.
EOT
,
    ],
    'Bash' => [
<<<'EOT'
#!/usr/bin/env bash
set -euo pipefail

backup_dir="/var/backups/opng"
mkdir -p "$backup_dir"

fecha=$(date +%Y%m%d-%H%M%S)
mysqldump --single-transaction opng | gzip > "$backup_dir/opng-$fecha.sql.gz"

find "$backup_dir" -name 'opng-*.sql.gz' -mtime +14 -delete
echo "Backup completado: $backup_dir/opng-$fecha.sql.gz"
EOT
,
<<<'EOT'
#!/usr/bin/env bash
# Renombra todos los .txt de un directorio a .md

for archivo in *.txt; do
  [ -e "$archivo" ] || continue
  destino="${archivo%.txt}.md"
  mv -- "$archivo" "$destino"
  echo "$archivo -> $destino"
done
EOT
,
<<<'EOT'
#!/usr/bin/env bash
# Comprueba si un puerto esta abierto

host="${1:-127.0.0.1}"
puerto="${2:-3306}"

if timeout 2 bash -c "</dev/tcp/$host/$puerto" 2>/dev/null; then
  echo "$host:$puerto OK"
else
  echo "$host:$puerto cerrado" >&2
  exit 1
fi
EOT
,
<<<'EOT'
#!/usr/bin/env bash
# Extrae las 10 IPs con mas peticiones del access.log

awk '{print $1}' /var/log/nginx/access.log \
  | sort \
  | uniq -c \
  | sort -rn \
  | head -n 10
EOT
,
    ],
    'Pascal' => [
<<<'EOT'
program OrdenarArreglo;

const
  N = 10;

type
  TArreglo = array[1..N] of Integer;

procedure Burbuja(var a: TArreglo);
var
  i, j, tmp: Integer;
begin
  for i := 1 to N - 1 do
    for j := 1 to N - i do
      if a[j] > a[j + 1] then
      begin
        tmp := a[j];
        a[j] := a[j + 1];
        a[j + 1] := tmp;
      end;
end;

begin
  WriteLn('Arreglo ordenado');
end.
EOT
,
<<<'EOT'
program Factorial;

function Fact(n: Integer): LongInt;
begin
  if n <= 1 then
    Fact := 1
  else
    Fact := n * Fact(n - 1);
end;

begin
  WriteLn('5! = ', Fact(5));
end.
EOT
,
<<<'EOT'
program BuscarBinario;

function Buscar(const a: array of Integer; objetivo: Integer): Integer;
var
  bajo, alto, medio: Integer;
begin
  bajo := 0;
  alto := High(a);
  while bajo <= alto do
  begin
    medio := (bajo + alto) div 2;
    if a[medio] = objetivo then
      Exit(medio)
    else if a[medio] < objetivo then
      bajo := medio + 1
    else
      alto := medio - 1;
  end;
  Buscar := -1;
end;
EOT
,
<<<'EOT'
program HolaMundo;
begin
  WriteLn('Hola desde Free Pascal');
end.
EOT
,
    ],
    'Python' => [
<<<'EOT'
from dataclasses import dataclass

@dataclass
class Paste:
    id: str
    topic: str
    language: str
    text: str

def resumen(pastes: list[Paste], limite: int = 50) -> list[dict]:
    return [
        {"id": p.id, "topic": p.topic, "language": p.language}
        for p in pastes[:limite]
    ]
EOT
,
<<<'EOT'
import re

PATRON_EMAIL = re.compile(r"^[\w.+-]+@[\w-]+\.[\w.-]+$")

def emails_validos(lineas: list[str]) -> list[str]:
    return [e.strip() for e in lineas if PATRON_EMAIL.match(e.strip())]

if __name__ == "__main__":
    print(emails_validos(["a@b.com", "no-es-email"]))
EOT
,
<<<'EOT'
from collections import Counter
import re

def palabras_mas_comunes(texto: str, n: int = 10) -> list[tuple[str, int]]:
    palabras = re.findall(r"\w+", texto.lower())
    return Counter(palabras).most_common(n)
EOT
,
<<<'EOT'
def fib(n: int) -> int:
    a, b = 0, 1
    for _ in range(n):
        a, b = b, a + b
    return a

print([fib(i) for i in range(10)])
EOT
,
    ],
    'PHP' => [
<<<'EOT'
<?php
function slugify(string $texto): string
{
    $texto = iconv('UTF-8', 'ASCII//TRANSLIT', $texto);
    $texto = strtolower($texto);
    $texto = preg_replace('/[^a-z0-9]+/', '-', $texto);
    return trim($texto, '-');
}

echo slugify('Hola, mundo PHP!'); // hola-mundo-php
EOT
,
<<<'EOT'
<?php
$db = new mysqli('localhost', 'root', 'root', 'opng', 8889);

$stmt = $db->prepare('SELECT id, topic FROM entries WHERE MATCH(topic) AGAINST (? IN BOOLEAN MODE) ORDER BY date DESC, id DESC LIMIT 51');
$q = '+docker*';
$stmt->bind_param('s', $q);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    echo $row['id'] . ' - ' . $row['topic'] . PHP_EOL;
}
EOT
,
<<<'EOT'
<?php
function csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
EOT
,
<<<'EOT'
<?php
// Redirigir siempre con exit para no seguir ejecutando codigo
if (!isset($_SESSION['user_id'])) {
    header('Location: ../error.php?msg=' . urlencode('Unauthorized'));
    exit;
}
EOT
,
    ],
    'Java/C/C++' => [
<<<'EOT'
#include <iostream>
#include <vector>
#include <algorithm>

int main() {
    std::vector<int> v{5, 3, 9, 1, 7};
    std::sort(v.begin(), v.end());
    for (int n : v) std::cout << n << ' ';
    std::cout << '\n';
    return 0;
}
EOT
,
<<<'EOT'
#include <stdio.h>
#include <stdlib.h>

int main(void) {
    int *buf = malloc(100 * sizeof(int));
    if (buf == NULL) {
        fprintf(stderr, "sin memoria\n");
        return 1;
    }
    for (int i = 0; i < 100; i++) buf[i] = i * i;
    printf("%d\n", buf[99]);
    free(buf);
    return 0;
}
EOT
,
<<<'EOT'
import java.util.HashMap;
import java.util.Map;

public class Contador {
    public static void main(String[] args) {
        String[] palabras = {"java", "c++", "java", "php"};
        Map<String, Integer> freq = new HashMap<>();
        for (String p : palabras) {
            freq.merge(p, 1, Integer::sum);
        }
        System.out.println(freq);
    }
}
EOT
,
<<<'EOT'
template <typename T>
T maximo(const std::vector<T>& v) {
    T m = v.front();
    for (const auto& x : v) {
        if (x > m) m = x;
    }
    return m;
}
EOT
,
    ],
];

// ---------------------------------------------------------------------------
// 60 pastes base
// ---------------------------------------------------------------------------
$topics = [
    'Docker compose para desarrollo local',
    'Consulta SQL lenta en el listado',
    'Script de backup diario de MySQL',
    'Regex para validar emails',
    'Ordenacion por burbuja en Pascal',
    'API REST minima en PHP',
    'Decoradores y dataclasses en Python',
    'Fuga de memoria en C++',
    'Recorrer ficheros en Bash',
    'Configurar Nginx como reverse proxy',
    'Indices en MySQL: cuando usarlos',
    'Depurar un segmentation fault',
    'Paginacion por cursor en PHP y MySQL',
    'Comprobar puertos abiertos con Bash',
    'Contar palabras en Python',
    'Evitar open redirects en login',
    'Hash de contrasenas con bcrypt',
    'Sesiones y cookies SameSite',
    'Como escapar HTML correctamente',
    'Prepared statements en mysqli',
    'Que es un CSRF token',
    'Migrar de PHP 7.4 a 8.3',
    'Errores comunes con LEFT JOIN',
    'Optimizar consultas con EXPLAIN',
    'Script para renombrar archivos',
    'Analizar un access.log de Nginx',
    'Busqueda binaria en Pascal',
    'Factorial recursivo en Pascal',
    'Generar slugs en PHP',
    'Validar formularios en servidor',
    'Diferencia entre TRUNCATE y DELETE',
    'Como funciona un indice B-Tree',
    'Manejo de errores en Bash',
    'Argumentos por referencia en PHP',
    'Alternativas a CDN para highlight.js',
    'Docker: volumenes y bind mounts',
    'Copias de seguridad con mysqldump',
    'Rate limiting por IP',
    'Captcha: buenas practicas',
    'Logs estructurados en PHP',
    'Que es mysqlnd',
    'Configurar PHP-FPM en produccion',
    'Rotar logs con logrotate',
    'Plantillas HTML sin framework',
    'Tablas responsivas en CSS',
    'Modo oscuro con localStorage',
    'Internacionalizacion con JSON',
    'Fetch de JSON y rutas relativas',
    'Comprobar el estado de un servicio',
    'Variables de entorno en systemd',
    'Monitorizar disco con df y du',
    'Ordenar un vector en C++',
    'Memoria dinamica en C',
    'HashMap en Java',
    'Plantillas genericas en C++',
    'Expresiones regulares en Python',
    'Contar frecuencia de palabras',
    'Leer argumentos en Bash',
    'Comprobar conexion a MySQL',
    'Buenas practicas para un README',
    'Checklist de despliegue',
];

$languages = ['Plaintext', 'Bash', 'Pascal', 'Python', 'PHP', 'Java/C/C++'];

/**
 * Elige un lenguaje coherente con el tema del paste.
 */
function pick_language(string $topic): string
{
    $t = strtolower($topic);
    $rules = [
        'Java/C/C++' => ['c++', 'java', 'memoria', 'vector', 'hashmap', 'plantillas', 'segmentation'],
        'Pascal'     => ['pascal'],
        'Python'     => ['python', 'regex', 'decoradores', 'dataclasses', 'palabras', 'frecuencia'],
        'Bash'       => ['bash', 'script', 'backup', 'puertos', 'access.log', 'logs', 'logrotate',
                         'systemd', 'disco', 'servicio', 'mysqldump', 'renombrar', 'argumentos',
                         'conexion a mysql'],
        'PHP'        => ['php', 'mysqli', 'sesiones', 'cookies', 'redirect', 'bcrypt', 'slug',
                         'formulario', 'paginacion', 'escapar html'],
    ];

    foreach ($rules as $language => $keywords) {
        foreach ($keywords as $keyword) {
            if (str_contains($t, $keyword)) {
                return $language;
            }
        }
    }

    return 'Plaintext';
}

$start = strtotime('-59 days 08:00:00');
$base_entries = []; // id, user_id, language, topic, text, date

for ($i = 0; $i < 60; $i++) {
    $topic = $topics[$i];
    $language = pick_language($topic);
    $snippet_list = $snippets[$language];
    $text = $snippet_list[$i % count($snippet_list)];

    // ~20% invitados, ~10% admins, resto usuarios normales
    if ($i % 5 === 0) {
        $user_id = null;
    } elseif ($i % 7 === 0) {
        $user_id = $admin_ids[$i % count($admin_ids)];
    } else {
        $user_id = $normal_ids[$i % count($normal_ids)];
    }

    $date = date('Y-m-d H:i:s', $start + $i * 86400 + ($i * 137) % 3600);

    $base_entries[] = [
        'id'       => sprintf('seed%04d', $i + 1),
        'user_id'  => $user_id,
        'language' => $language,
        'topic'    => $topic,
        'text'     => $text,
        'date'     => $date,
    ];
}

// ---------------------------------------------------------------------------
// 12 respuestas (RE:) a algunos pastes base
// ---------------------------------------------------------------------------
$reply_texts = [
    "Gracias, me sirvio tal cual. Solo tuve que cambiar la ruta del directorio.",
    "Ojo: en PHP 8.3 la comprobacion de tipos es mas estricta, revisa los nullable.",
    "Yo anadiria un indice compuesto por (user_id, date) si filtras por autor.",
    "En mi caso el problema era que el LEFT JOIN no filtraba los NULL; lo resolvi con COALESCE.",
    "Probado en Debian 12 y funciona. En Alpine hay que instalar bash aparte.",
    "Una alternativa mas simple: usar un bucle while con read -r.",
    "Cuidado con el OFFSET alto, se vuelve lento; mejor cursor por fecha.",
    "El snippet de C++ compila con -std=c++17 sin warnings.",
    "Anade set -euo pipefail al principio del script y evitas sustos.",
    "¿Funciona tambien con MariaDB 11? Lo pruebo esta tarde.",
    "Muchas gracias, justo lo que necesitaba para el despliegue del viernes.",
    "Correccion: el token debe regenerarse tambien al cerrar sesion.",
];

$reply_parents = [2, 5, 8, 11, 14, 17, 20, 23, 26, 32, 41, 50];
$entries = $base_entries;

foreach ($reply_texts as $k => $reply_text) {
    $parent = $base_entries[$reply_parents[$k] - 1];

    // La respuesta la escribe otro usuario (o un invitado si el padre era de invitado)
    $user_id = $parent['user_id'];
    if ($user_id === null) {
        $user_id = $normal_ids[($k + 3) % count($normal_ids)];
    } else {
        $candidate = $normal_ids[($k + (int) $parent['user_id']) % count($normal_ids)];
        if ($candidate === $user_id) {
            $candidate = $normal_ids[($k + 1) % count($normal_ids)];
        }
        $user_id = $candidate;
    }

    $parent_first_line = strtok($parent['text'], "\n");
    $entries[] = [
        'id'       => sprintf('seed1%03d', $k + 1),
        'user_id'  => $user_id,
        'language' => $parent['language'],
        'topic'    => 'RE: ' . $parent['topic'],
        'text'     => "> " . $parent_first_line . "\n\n" . $reply_text,
        'date'     => date('Y-m-d H:i:s', strtotime($parent['date']) + (2 + $k * 3) * 3600),
    ];
}

// ---------------------------------------------------------------------------
// Insercion
// ---------------------------------------------------------------------------
$stmt = $db->prepare("INSERT INTO entries (id, user_id, language, text, topic, date) VALUES (?, ?, ?, ?, ?, ?)");
$inserted = 0;
foreach ($entries as $e) {
    $stmt->bind_param('sissss', $e['id'], $e['user_id'], $e['language'], $e['text'], $e['topic'], $e['date']);
    if (!$stmt->execute()) {
        fwrite(STDERR, "ERROR insert {$e['id']}: " . $stmt->error . "\n");
        continue;
    }
    $inserted++;
}
$stmt->close();

// ---------------------------------------------------------------------------
// Resumen
// ---------------------------------------------------------------------------
$total_entries = $db->query("SELECT COUNT(*) c FROM entries")->fetch_assoc()['c'];
$total_users   = $db->query("SELECT COUNT(*) c FROM users")->fetch_assoc()['c'];
$total_replies = $db->query("SELECT COUNT(*) c FROM entries WHERE topic LIKE 'RE:%'")->fetch_assoc()['c'];
$total_guest   = $db->query("SELECT COUNT(*) c FROM entries WHERE user_id IS NULL")->fetch_assoc()['c'];
$admins        = $db->query("SELECT COUNT(*) c FROM users WHERE role='admin'")->fetch_assoc()['c'];

echo "Seed completado\n";
echo "  Usuarios:      $total_users (admins: $admins)\n";
echo "  Entradas:      $total_entries (insertadas ahora: $inserted)\n";
echo "  Respuestas:    $total_replies\n";
echo "  De invitados:  $total_guest\n";
echo "  Contrasena de los usuarios seed: Password123!\n";
echo "  Emails: <usuario>@example.test (ej. lucia@example.test)\n";

$db->close();
