<?php
// =============================================
// CEDEKA WC — Sync automático de jugadores
// Usa la API pública football-data.org (free tier)
// =============================================
// SETUP:
//  1. Copiar este archivo a: cedeka/admin/sync_players.php
//  2. Agregar al .env / config.php: FOOTBALL_API_KEY=tu_api_key
//  3. Obtener API key gratis en: https://www.football-data.org/client/register
//  4. Llamarlo desde la interfaz admin O por cron: php sync_players.php
// =============================================

require_once __DIR__ . '/../includes/config.php';

// Si se llama por web, solo admins
if (php_sapi_name() !== 'cli') {
    session_start();
    $user = getCurrentUser();
    if (!$user || $user['role'] !== 'admin') {
        http_response_code(403);
        die(json_encode(['error' => 'Solo admins']));
    }
    header('Content-Type: application/json');
}

// =============================================
// CONFIG
// =============================================
define('API_KEY',      getenv('FOOTBALL_API_KEY') ?: (defined('FOOTBALL_API_KEY') ? FOOTBALL_API_KEY : ''));
define('API_BASE',     'https://api.football-data.org/v4/');
define('WC2026_ID',    2000); // FIFA World Cup 2026 en football-data.org
define('CACHE_TTL',    3600); // 1 hora — el free tier tiene 10 req/min
define('CACHE_DIR',    sys_get_temp_dir() . '/cedeka_cache/');

@mkdir(CACHE_DIR, 0700, true);

// =============================================
// MAPA de nombres: como los tenés en tu BD  →  nombre en la API
// Ajustá según tus datos de matches
// =============================================
const TEAM_MAP = [
    // CONMEBOL
    'Argentina'         => 'Argentina',
    'Brasil'            => 'Brazil',
    'Colombia'          => 'Colombia',
    'Uruguay'           => 'Uruguay',
    'Ecuador'           => 'Ecuador',
    'Paraguay'          => 'Paraguay',
    // CONCACAF
    'México'            => 'Mexico',
    'Estados Unidos'    => 'USA',
    'Canadá'            => 'Canada',
    'Panamá'            => 'Panama',
    'Haití'             => 'Haiti',
    'Curazao'           => 'Curaçao',
    // UEFA
    'Francia'           => 'France',
    'Alemania'          => 'Germany',
    'Portugal'          => 'Portugal',
    'Inglaterra'        => 'England',
    'España'            => 'Spain',
    'Países Bajos'      => 'Netherlands',
    'Bélgica'           => 'Belgium',
    'Croacia'           => 'Croatia',
    'Suiza'             => 'Switzerland',
    'Austria'           => 'Austria',
    'Noruega'           => 'Norway',
    'Escocia'           => 'Scotland',
    'Rep. Checa'        => 'Czech Republic',
    'Suecia'            => 'Sweden',
    'Bosnia y Herz.'    => 'Bosnia and Herzegovina',
    'Turquía'           => 'Turkey',
    'Argelia'           => 'Algeria',
    'Uzbekistán'        => 'Uzbekistan',
    'Jordania'          => 'Jordan',
    // AFC
    'Japón'             => 'Japan',
    'Corea del Sur'     => 'South Korea',
    'Arabia Saudita'    => 'Saudi Arabia',
    'Qatar'             => 'Qatar',
    'Irak'              => 'Iraq',
    'Irán'              => 'Iran',
    'Australia'         => 'Australia',
    // CAF
    'Marruecos'         => 'Morocco',
    'Senegal'           => 'Senegal',
    'Costa de Marfil'   => "Côte d'Ivoire",
    'Túnez'             => 'Tunisia',
    'Egipto'            => 'Egypt',
    'Sudáfrica'         => 'South Africa',
    'RD Congo'          => 'DR Congo',
    'Ghana'             => 'Ghana',
    'Cabo Verde'        => 'Cape Verde',
    // OFC
    'Nueva Zelanda'     => 'New Zealand',
];

// =============================================
// FALLBACK: convocados hardcoded del Mundial 2026
// Se usa si la API no devuelve datos (free tier limitado)
// Datos extraídos de fuentes oficiales al 2 jun 2026
// =============================================
const SQUADS_FALLBACK = [
    'Argentina' => [
        ['name'=>'Emiliano Martínez','number'=>1,'pos'=>'GK'],
        ['name'=>'Gerónimo Rulli','number'=>12,'pos'=>'GK'],
        ['name'=>'Juan Musso','number'=>23,'pos'=>'GK'],
        ['name'=>'Gonzalo Montiel','number'=>2,'pos'=>'DEF'],
        ['name'=>'Nahuel Molina','number'=>14,'pos'=>'DEF'],
        ['name'=>'Lisandro Martínez','number'=>5,'pos'=>'DEF'],
        ['name'=>'Nicolás Otamendi','number'=>19,'pos'=>'DEF'],
        ['name'=>'Leonardo Balerdi','number'=>6,'pos'=>'DEF'],
        ['name'=>'Cristian Romero','number'=>13,'pos'=>'DEF'],
        ['name'=>'Facundo Medina','number'=>3,'pos'=>'DEF'],
        ['name'=>'Nicolás Tagliafico','number'=>8,'pos'=>'DEF'],
        ['name'=>'Leandro Paredes','number'=>5,'pos'=>'MID'],
        ['name'=>'Rodrigo De Paul','number'=>7,'pos'=>'MID'],
        ['name'=>'Exequiel Palacios','number'=>14,'pos'=>'MID'],
        ['name'=>'Enzo Fernández','number'=>24,'pos'=>'MID'],
        ['name'=>'Alexis Mac Allister','number'=>20,'pos'=>'MID'],
        ['name'=>'Giovani Lo Celso','number'=>15,'pos'=>'MID'],
        ['name'=>'Valentín Barco','number'=>16,'pos'=>'MID'],
        ['name'=>'Lionel Messi','number'=>10,'pos'=>'FWD'],
        ['name'=>'Lautaro Martínez','number'=>22,'pos'=>'FWD'],
        ['name'=>'Julián Álvarez','number'=>9,'pos'=>'FWD'],
        ['name'=>'Nicolás González','number'=>11,'pos'=>'FWD'],
        ['name'=>'Giuliano Simeone','number'=>17,'pos'=>'FWD'],
        ['name'=>'Nicolás Paz','number'=>18,'pos'=>'MID'],
        ['name'=>'Thiago Almada','number'=>21,'pos'=>'MID'],
        ['name'=>'José Manuel López','number'=>4,'pos'=>'DEF'],
    ],
    'Brasil' => [
        ['name'=>'Alisson','number'=>1,'pos'=>'GK'],
        ['name'=>'Ederson','number'=>12,'pos'=>'GK'],
        ['name'=>'Weverton','number'=>23,'pos'=>'GK'],
        ['name'=>'Marquinhos','number'=>4,'pos'=>'DEF'],
        ['name'=>'Gabriel Magalhães','number'=>3,'pos'=>'DEF'],
        ['name'=>'Léo Pereira','number'=>14,'pos'=>'DEF'],
        ['name'=>'Bremer','number'=>5,'pos'=>'DEF'],
        ['name'=>'Danilo','number'=>2,'pos'=>'DEF'],
        ['name'=>'Wesley','number'=>22,'pos'=>'DEF'],
        ['name'=>'Ibáñez','number'=>6,'pos'=>'DEF'],
        ['name'=>'Alex Sandro','number'=>6,'pos'=>'DEF'],
        ['name'=>'Douglas Santos','number'=>15,'pos'=>'DEF'],
        ['name'=>'Casemiro','number'=>5,'pos'=>'MID'],
        ['name'=>'Bruno Guimarães','number'=>17,'pos'=>'MID'],
        ['name'=>'Fabinho','number'=>15,'pos'=>'MID'],
        ['name'=>'Lucas Paquetá','number'=>10,'pos'=>'MID'],
        ['name'=>'Vinicius Jr.','number'=>7,'pos'=>'FWD'],
        ['name'=>'Raphinha','number'=>11,'pos'=>'FWD'],
        ['name'=>'Neymar Jr.','number'=>10,'pos'=>'FWD'],
        ['name'=>'Matheus Cunha','number'=>9,'pos'=>'FWD'],
        ['name'=>'Gabriel Martinelli','number'=>11,'pos'=>'FWD'],
        ['name'=>'Endrick','number'=>9,'pos'=>'FWD'],
        ['name'=>'Rayan','number'=>19,'pos'=>'FWD'],
        ['name'=>'Igor Thiago','number'=>20,'pos'=>'FWD'],
        ['name'=>'Luiz Henrique','number'=>21,'pos'=>'FWD'],
    ],
    'España' => [
        ['name'=>'Unai Simón','number'=>1,'pos'=>'GK'],
        ['name'=>'David Raya','number'=>13,'pos'=>'GK'],
        ['name'=>'Joan García','number'=>23,'pos'=>'GK'],
        ['name'=>'Marc Cucurella','number'=>3,'pos'=>'DEF'],
        ['name'=>'Alejandro Grimaldo','number'=>18,'pos'=>'DEF'],
        ['name'=>'Pau Cubarsí','number'=>5,'pos'=>'DEF'],
        ['name'=>'Aymeric Laporte','number'=>14,'pos'=>'DEF'],
        ['name'=>'Marc Pubill','number'=>2,'pos'=>'DEF'],
        ['name'=>'Eric García','number'=>24,'pos'=>'DEF'],
        ['name'=>'Marcos Llorente','number'=>16,'pos'=>'DEF'],
        ['name'=>'Pedro Porro','number'=>22,'pos'=>'DEF'],
        ['name'=>'Pedri','number'=>8,'pos'=>'MID'],
        ['name'=>'Fabián Ruiz','number'=>7,'pos'=>'MID'],
        ['name'=>'Martín Zubimendi','number'=>15,'pos'=>'MID'],
        ['name'=>'Gavi','number'=>9,'pos'=>'MID'],
        ['name'=>'Rodri','number'=>16,'pos'=>'MID'],
        ['name'=>'Álex Baena','number'=>19,'pos'=>'MID'],
        ['name'=>'Mikel Merino','number'=>20,'pos'=>'MID'],
        ['name'=>'Mikel Oyarzabal','number'=>17,'pos'=>'MID'],
        ['name'=>'Dani Olmo','number'=>10,'pos'=>'FWD'],
        ['name'=>'Nico Williams','number'=>11,'pos'=>'FWD'],
        ['name'=>'Lamine Yamal','number'=>19,'pos'=>'FWD'],
        ['name'=>'Ferran Torres','number'=>21,'pos'=>'FWD'],
        ['name'=>'Borja Iglesias','number'=>9,'pos'=>'FWD'],
        ['name'=>'Yeremy Pino','number'=>23,'pos'=>'FWD'],
        ['name'=>'Víctor Muñoz','number'=>6,'pos'=>'DEF'],
    ],
    'Francia' => [
        ['name'=>'Mike Maignan','number'=>1,'pos'=>'GK'],
        ['name'=>'Robin Risser','number'=>16,'pos'=>'GK'],
        ['name'=>'Brice Samba','number'=>23,'pos'=>'GK'],
        ['name'=>'Lucas Digne','number'=>3,'pos'=>'DEF'],
        ['name'=>'Malo Gusto','number'=>2,'pos'=>'DEF'],
        ['name'=>'Lucas Hernández','number'=>21,'pos'=>'DEF'],
        ['name'=>'Theo Hernández','number'=>22,'pos'=>'DEF'],
        ['name'=>'Ibrahima Konaté','number'=>5,'pos'=>'DEF'],
        ['name'=>'Jules Koundé','number'=>4,'pos'=>'DEF'],
        ['name'=>'Maxence Lacroix','number'=>24,'pos'=>'DEF'],
        ['name'=>'William Saliba','number'=>17,'pos'=>'DEF'],
        ['name'=>'Dayot Upamecano','number'=>15,'pos'=>'DEF'],
        ['name'=>"N'Golo Kanté",'number'=>13,'pos'=>'MID'],
        ['name'=>'Manu Koné','number'=>14,'pos'=>'MID'],
        ['name'=>'Adrien Rabiot','number'=>6,'pos'=>'MID'],
        ['name'=>'Aurélien Tchouaméni','number'=>8,'pos'=>'MID'],
        ['name'=>'Warren Zaïre-Emery','number'=>20,'pos'=>'MID'],
        ['name'=>'Kylian Mbappé','number'=>10,'pos'=>'FWD'],
        ['name'=>'Ousmane Dembélé','number'=>11,'pos'=>'FWD'],
        ['name'=>'Marcus Thuram','number'=>9,'pos'=>'FWD'],
        ['name'=>'Bradley Barcola','number'=>7,'pos'=>'FWD'],
        ['name'=>'Désiré Doué','number'=>18,'pos'=>'FWD'],
        ['name'=>'Rayan Cherki','number'=>19,'pos'=>'FWD'],
        ['name'=>'Maghnes Akliouche','number'=>12,'pos'=>'FWD'],
        ['name'=>'Jean-Philippe Mateta','number'=>16,'pos'=>'FWD'],
        ['name'=>'Michael Olise','number'=>23,'pos'=>'FWD'],
    ],
    'Alemania' => [
        ['name'=>'Manuel Neuer','number'=>1,'pos'=>'GK'],
        ['name'=>'Oliver Baumann','number'=>12,'pos'=>'GK'],
        ['name'=>'Alexander Nübel','number'=>23,'pos'=>'GK'],
        ['name'=>'Antonio Rüdiger','number'=>2,'pos'=>'DEF'],
        ['name'=>'Jonathan Tah','number'=>5,'pos'=>'DEF'],
        ['name'=>'Nico Schlotterbeck','number'=>4,'pos'=>'DEF'],
        ['name'=>'Waldemar Anton','number'=>21,'pos'=>'DEF'],
        ['name'=>'David Raum','number'=>3,'pos'=>'DEF'],
        ['name'=>'Malick Thiaw','number'=>15,'pos'=>'DEF'],
        ['name'=>'Nathanael Brown','number'=>22,'pos'=>'DEF'],
        ['name'=>'Joshua Kimmich','number'=>6,'pos'=>'MID'],
        ['name'=>'Leon Goretzka','number'=>8,'pos'=>'MID'],
        ['name'=>'Pascal Groß','number'=>13,'pos'=>'MID'],
        ['name'=>'Aleksandar Pavlović','number'=>24,'pos'=>'MID'],
        ['name'=>'Angelo Stiller','number'=>20,'pos'=>'MID'],
        ['name'=>'Felix Nmecha','number'=>17,'pos'=>'MID'],
        ['name'=>'Nadiem Amiri','number'=>19,'pos'=>'MID'],
        ['name'=>'Florian Wirtz','number'=>10,'pos'=>'FWD'],
        ['name'=>'Jamal Musiala','number'=>14,'pos'=>'FWD'],
        ['name'=>'Kai Havertz','number'=>7,'pos'=>'FWD'],
        ['name'=>'Leroy Sané','number'=>11,'pos'=>'FWD'],
        ['name'=>'Maximilian Beier','number'=>9,'pos'=>'FWD'],
        ['name'=>'Deniz Undav','number'=>18,'pos'=>'FWD'],
        ['name'=>'Nick Woltemade','number'=>16,'pos'=>'FWD'],
        ['name'=>'Jamie Leweling','number'=>23,'pos'=>'FWD'],
        ['name'=>'Lennart Karl','number'=>25,'pos'=>'FWD'],
    ],
    'Portugal' => [
        ['name'=>'Diogo Costa','number'=>1,'pos'=>'GK'],
        ['name'=>'José Sá','number'=>12,'pos'=>'GK'],
        ['name'=>'Rui Silva','number'=>22,'pos'=>'GK'],
        ['name'=>'Ricardo Velho','number'=>23,'pos'=>'GK'],
        ['name'=>'Diogo Dalot','number'=>2,'pos'=>'DEF'],
        ['name'=>'João Cancelo','number'=>20,'pos'=>'DEF'],
        ['name'=>'Nélson Semedo','number'=>21,'pos'=>'DEF'],
        ['name'=>'Nuno Mendes','number'=>19,'pos'=>'DEF'],
        ['name'=>'Rúben Dias','number'=>4,'pos'=>'DEF'],
        ['name'=>'Gonçalo Inácio','number'=>3,'pos'=>'DEF'],
        ['name'=>'Renato Veiga','number'=>5,'pos'=>'DEF'],
        ['name'=>'Tomás Araújo','number'=>16,'pos'=>'DEF'],
        ['name'=>'Matheus Nunes','number'=>8,'pos'=>'DEF'],
        ['name'=>'Rúben Neves','number'=>15,'pos'=>'MID'],
        ['name'=>'João Neves','number'=>17,'pos'=>'MID'],
        ['name'=>'Vitinha','number'=>13,'pos'=>'MID'],
        ['name'=>'Bruno Fernandes','number'=>8,'pos'=>'MID'],
        ['name'=>'Bernardo Silva','number'=>10,'pos'=>'MID'],
        ['name'=>'Samuel Costa','number'=>24,'pos'=>'MID'],
        ['name'=>'Cristiano Ronaldo','number'=>7,'pos'=>'FWD'],
        ['name'=>'Rafael Leão','number'=>11,'pos'=>'FWD'],
        ['name'=>'Gonçalo Ramos','number'=>9,'pos'=>'FWD'],
        ['name'=>'João Félix','number'=>22,'pos'=>'FWD'],
        ['name'=>'Pedro Neto','number'=>17,'pos'=>'FWD'],
        ['name'=>'Gonçalo Guedes','number'=>18,'pos'=>'FWD'],
        ['name'=>'Francisco Conceição','number'=>21,'pos'=>'FWD'],
    ],
    'México' => [
        ['name'=>'Guillermo Ochoa','number'=>13,'pos'=>'GK'],
        ['name'=>'Raúl Rangel','number'=>1,'pos'=>'GK'],
        ['name'=>'Carlos Acevedo','number'=>23,'pos'=>'GK'],
        ['name'=>'César Montes','number'=>3,'pos'=>'DEF'],
        ['name'=>'Edson Álvarez','number'=>18,'pos'=>'DEF'],
        ['name'=>'Israel Reyes','number'=>4,'pos'=>'DEF'],
        ['name'=>'Jesús Gallardo','number'=>23,'pos'=>'DEF'],
        ['name'=>'Johan Vásquez','number'=>5,'pos'=>'DEF'],
        ['name'=>'Jorge Sánchez','number'=>2,'pos'=>'DEF'],
        ['name'=>'Mateo Chávez','number'=>22,'pos'=>'DEF'],
        ['name'=>'Álvaro Fidalgo','number'=>8,'pos'=>'MID'],
        ['name'=>'Brian Gutiérrez','number'=>17,'pos'=>'MID'],
        ['name'=>'Erik Lira','number'=>16,'pos'=>'MID'],
        ['name'=>'Luis Chávez','number'=>7,'pos'=>'MID'],
        ['name'=>'Luis Romo','number'=>6,'pos'=>'MID'],
        ['name'=>'Obed Vargas','number'=>14,'pos'=>'MID'],
        ['name'=>'Orbelín Pineda','number'=>21,'pos'=>'MID'],
        ['name'=>'Gilberto Mora','number'=>19,'pos'=>'MID'],
        ['name'=>'Santiago Giménez','number'=>9,'pos'=>'FWD'],
        ['name'=>'Raúl Jiménez','number'=>10,'pos'=>'FWD'],
        ['name'=>'Roberto Alvarado','number'=>11,'pos'=>'FWD'],
        ['name'=>'César Huerta','number'=>20,'pos'=>'FWD'],
        ['name'=>'Alexis Vega','number'=>15,'pos'=>'FWD'],
        ['name'=>'Julián Quiñones','number'=>24,'pos'=>'FWD'],
        ['name'=>'Armando González','number'=>25,'pos'=>'FWD'],
        ['name'=>'Guillermo Martínez','number'=>19,'pos'=>'FWD'],
    ],
    'Colombia' => [
        ['name'=>'David Ospina','number'=>1,'pos'=>'GK'],
        ['name'=>'Álvaro Montero','number'=>12,'pos'=>'GK'],
        ['name'=>'Camilo Vargas','number'=>23,'pos'=>'GK'],
        ['name'=>'Daniel Muñoz','number'=>2,'pos'=>'DEF'],
        ['name'=>'Jhon Lucumí','number'=>3,'pos'=>'DEF'],
        ['name'=>'Davinson Sánchez','number'=>4,'pos'=>'DEF'],
        ['name'=>'Santiago Arias','number'=>22,'pos'=>'DEF'],
        ['name'=>'Álvaro Angulo','number'=>13,'pos'=>'DEF'],
        ['name'=>'Johan Mojica','number'=>18,'pos'=>'DEF'],
        ['name'=>'Willer Ditta','number'=>5,'pos'=>'DEF'],
        ['name'=>'Deiver Machado','number'=>21,'pos'=>'DEF'],
        ['name'=>'James Rodríguez','number'=>10,'pos'=>'MID'],
        ['name'=>'Jorge Carrascal','number'=>8,'pos'=>'MID'],
        ['name'=>'Jefferson Lerma','number'=>6,'pos'=>'MID'],
        ['name'=>'Richard Ríos','number'=>17,'pos'=>'MID'],
        ['name'=>'Jhon Arias','number'=>7,'pos'=>'MID'],
        ['name'=>'Juan Fernando Quintero','number'=>11,'pos'=>'MID'],
        ['name'=>'Kevin Castaño','number'=>19,'pos'=>'MID'],
        ['name'=>'Juan Camilo Portilla','number'=>20,'pos'=>'MID'],
        ['name'=>'Luis Díaz','number'=>7,'pos'=>'FWD'],
        ['name'=>'Cucho Hernández','number'=>9,'pos'=>'FWD'],
        ['name'=>'Jhon Durán','number'=>11,'pos'=>'FWD'],
        ['name'=>'Jhon Córdoba','number'=>15,'pos'=>'FWD'],
        ['name'=>'Luis Suárez','number'=>24,'pos'=>'FWD'],
        ['name'=>'Andrés Gómez','number'=>25,'pos'=>'FWD'],
        ['name'=>'Jaminton Campaz','number'=>16,'pos'=>'FWD'],
    ],
    'Uruguay' => [
        ['name'=>'Sergio Rochet','number'=>1,'pos'=>'GK'],
        ['name'=>'Fernando Muslera','number'=>12,'pos'=>'GK'],
        ['name'=>'Santiago Mele','number'=>23,'pos'=>'GK'],
        ['name'=>'Ronald Araujo','number'=>4,'pos'=>'DEF'],
        ['name'=>'José María Giménez','number'=>2,'pos'=>'DEF'],
        ['name'=>'Santiago Bueno','number'=>5,'pos'=>'DEF'],
        ['name'=>'Guillermo Varela','number'=>22,'pos'=>'DEF'],
        ['name'=>'Mathías Olivera','number'=>3,'pos'=>'DEF'],
        ['name'=>'Sebastián Cáceres','number'=>16,'pos'=>'DEF'],
        ['name'=>'Joaquín Piquerez','number'=>21,'pos'=>'DEF'],
        ['name'=>'Matías Viña','number'=>13,'pos'=>'DEF'],
        ['name'=>'Federico Valverde','number'=>8,'pos'=>'MID'],
        ['name'=>'Manuel Ugarte','number'=>15,'pos'=>'MID'],
        ['name'=>'Rodrigo Bentancur','number'=>25,'pos'=>'MID'],
        ['name'=>'Giorgan de Arrascaeta','number'=>10,'pos'=>'MID'],
        ['name'=>'Nicolás de la Cruz','number'=>14,'pos'=>'MID'],
        ['name'=>'Rodrigo Zalazar','number'=>17,'pos'=>'MID'],
        ['name'=>'Facundo Pellistri','number'=>19,'pos'=>'MID'],
        ['name'=>'Maximiliano Araújo','number'=>20,'pos'=>'MID'],
        ['name'=>'Brian Rodríguez','number'=>11,'pos'=>'MID'],
        ['name'=>'Agustín Canobbio','number'=>9,'pos'=>'MID'],
        ['name'=>'Juan Manuel Sanabria','number'=>18,'pos'=>'MID'],
        ['name'=>'Emiliano Martínez','number'=>7,'pos'=>'MID'],
        ['name'=>'Darwin Núñez','number'=>9,'pos'=>'FWD'],
        ['name'=>'Federico Viñas','number'=>24,'pos'=>'FWD'],
        ['name'=>'Rodrigo Aguirre','number'=>21,'pos'=>'FWD'],
    ],
];

// =============================================
// FUNCIONES CORE
// =============================================

function apiGet(string $endpoint): ?array {
    if (!API_KEY) return null;

    $cacheFile = CACHE_DIR . md5($endpoint) . '.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < CACHE_TTL) {
        return json_decode(file_get_contents($cacheFile), true);
    }

    $ch = curl_init(API_BASE . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['X-Auth-Token: ' . API_KEY],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$body) return null;

    file_put_contents($cacheFile, $body);
    return json_decode($body, true);
}

/**
 * Busca el ID del equipo en football-data.org por nombre
 */
function findTeamId(string $cedekaName): ?int {
    $apiName = TEAM_MAP[$cedekaName] ?? $cedekaName;

    $data = apiGet('competitions/' . WC2026_ID . '/teams');
    if (!$data || empty($data['teams'])) return null;

    foreach ($data['teams'] as $t) {
        if (
            strcasecmp($t['name'], $apiName) === 0 ||
            strcasecmp($t['shortName'] ?? '', $apiName) === 0 ||
            strcasecmp($t['tla'] ?? '', $apiName) === 0
        ) {
            return (int)$t['id'];
        }
    }
    return null;
}

/**
 * Obtiene la plantilla de un equipo desde la API
 */
function fetchSquadFromAPI(string $cedekaName): array {
    $teamId = findTeamId($cedekaName);
    if (!$teamId) return [];

    $data = apiGet("teams/{$teamId}");
    if (!$data || empty($data['squad'])) return [];

    $players = [];
    foreach ($data['squad'] as $p) {
        $pos = match($p['position'] ?? '') {
            'Goalkeeper'  => 'GK',
            'Defence'     => 'DEF',
            'Midfield'    => 'MID',
            'Offence'     => 'FWD',
            default       => 'MID',
        };
        $players[] = [
            'name'   => $p['name'],
            'number' => $p['shirtNumber'] ?? null,
            'pos'    => $pos,
        ];
    }
    return $players;
}

/**
 * Obtiene jugadores: primero API, si falla usa fallback hardcoded
 */
function getSquad(string $teamName): array {
    // 1. Intentar API
    if (API_KEY) {
        $squad = fetchSquadFromAPI($teamName);
        if (!empty($squad)) return $squad;
    }

    // 2. Fallback hardcoded
    if (isset(SQUADS_FALLBACK[$teamName])) {
        return SQUADS_FALLBACK[$teamName];
    }

    // 3. Buscar por nombre de API
    $apiName = TEAM_MAP[$teamName] ?? null;
    if ($apiName && isset(SQUADS_FALLBACK[$apiName])) {
        return SQUADS_FALLBACK[$apiName];
    }

    return [];
}

/**
 * Sincroniza los jugadores de un partido en la BD
 * Modo: 'merge' (agrega sin borrar) | 'replace' (borra y recarga)
 */
function syncMatchPlayers(PDO $db, int $matchId, string $homeTeam, string $awayTeam, string $mode = 'merge'): array {
    $results = [];

    foreach ([$homeTeam, $awayTeam] as $team) {
        $squad = getSquad($team);

        if (empty($squad)) {
            $results[$team] = ['status' => 'no_data', 'count' => 0];
            continue;
        }

        if ($mode === 'replace') {
            $db->prepare("DELETE FROM match_players WHERE match_id=? AND team=?")->execute([$matchId, $team]);
        }

        $inserted = 0;
        foreach ($squad as $p) {
            try {
                $stmt = $db->prepare(
                    "INSERT IGNORE INTO match_players (match_id, team, player_name, jersey_number, position)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $stmt->execute([$matchId, $team, $p['name'], $p['number'] ?? null, $p['pos']]);
                if ($stmt->rowCount() > 0) $inserted++;
            } catch (PDOException $e) {
                // player_name UNIQUE per match+team — skip duplicates silently
            }
        }

        $results[$team] = ['status' => 'ok', 'count' => $inserted, 'source' => API_KEY ? 'api' : 'fallback'];
    }

    return $results;
}

// =============================================
// MAIN — Sincronizar todos los partidos abiertos
// o el match_id específico pasado por parámetro
// =============================================

$db      = getDB();
$matchId = (int)(php_sapi_name() === 'cli' ? ($argv[1] ?? 0) : ($_GET['match_id'] ?? 0));
$mode    = php_sapi_name() === 'cli' ? ($argv[2] ?? 'merge') : ($_GET['mode'] ?? 'merge');

if ($matchId) {
    // Sincronizar un partido específico
    $stmt = $db->prepare("SELECT * FROM matches WHERE id=?");
    $stmt->execute([$matchId]);
    $match = $stmt->fetch();

    if (!$match) {
        echo json_encode(['error' => "Partido #$matchId no encontrado"]);
        exit(1);
    }

    $result = syncMatchPlayers($db, $matchId, $match['home_team'], $match['away_team'], $mode);
    echo json_encode([
        'match_id' => $matchId,
        'match'    => "{$match['home_team']} vs {$match['away_team']}",
        'mode'     => $mode,
        'results'  => $result,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} else {
    // Sincronizar todos los partidos abiertos sin jugadores cargados
    $stmt = $db->query("
        SELECT m.* FROM matches m
        WHERE m.status IN ('open','closed')
          AND (SELECT COUNT(*) FROM match_players mp WHERE mp.match_id = m.id) = 0
        ORDER BY m.match_date ASC
    ");
    $matches = $stmt->fetchAll();

    $summary = [];
    foreach ($matches as $m) {
        $result = syncMatchPlayers($db, (int)$m['id'], $m['home_team'], $m['away_team'], $mode);
        $summary[] = [
            'match_id' => $m['id'],
            'match'    => "{$m['home_team']} vs {$m['away_team']}",
            'results'  => $result,
        ];
    }

    echo json_encode([
        'synced'  => count($summary),
        'mode'    => $mode,
        'details' => $summary,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}