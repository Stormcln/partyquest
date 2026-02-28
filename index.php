<?php
declare(strict_types=1);

/**
 * La Confrerie - PHP edition
 * - No AI dependencies
 * - Server-side persistence in data/app_data.json
 * - Session persistence for mobile usage
 */

date_default_timezone_set('Europe/Paris');

// Best-effort limits for mobile video uploads (depends on host policy).
if (function_exists('ini_set')) {
    @ini_set('upload_max_filesize', '256M');
    @ini_set('post_max_size', '256M');
    @ini_set('max_file_uploads', '20');
    @ini_set('max_execution_time', '180');
    @ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 365 * 10));
}

session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 365 * 10,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

const DATA_DIR = __DIR__ . '/data';
const DATA_FILE = DATA_DIR . '/app_data.json';
const UPLOAD_DIR = __DIR__ . '/uploads';
const REMEMBER_COOKIE = 'confrerie_remember';
const REMEMBER_TTL = 60 * 60 * 24 * 365 * 10;

const ROLE_ADMIN = 'ADMIN';
const ROLE_MEMBER = 'MEMBER';

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function app_cut(string $value, int $length): string {
    if (function_exists('mb_substr')) {
        return (string) mb_substr($value, 0, $length);
    }
    return substr($value, 0, $length);
}

function app_secret_key(): string {
    $raw = hash('sha256', __FILE__ . '|' . php_uname() . '|confrerie', true);
    return base64_encode($raw);
}

function app_remember_signature(string $uid): string {
    return hash_hmac('sha256', $uid, app_secret_key());
}

function app_set_remember_cookie(string $uid): void {
    $payload = $uid . ':' . app_remember_signature($uid);
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie(REMEMBER_COOKIE, $payload, [
        'expires' => time() + REMEMBER_TTL,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function app_clear_remember_cookie(): void {
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie(REMEMBER_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function app_refresh_session_cookie(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    if (headers_sent()) {
        return;
    }
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie(session_name(), session_id(), [
        'expires' => time() + REMEMBER_TTL,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function app_restore_uid_from_cookie(array $data): void {
    if (isset($_SESSION['uid']) && is_string($_SESSION['uid']) && $_SESSION['uid'] !== '') {
        return;
    }
    $raw = (string) ($_COOKIE[REMEMBER_COOKIE] ?? '');
    if ($raw === '' || strpos($raw, ':') === false) {
        return;
    }
    [$uid, $sig] = explode(':', $raw, 2);
    $uid = trim($uid);
    if ($uid === '' || $sig === '') {
        return;
    }
    $idx = app_find_user_index($data, $uid);
    if ($idx < 0) {
        app_clear_remember_cookie();
        return;
    }
    if (!hash_equals(app_remember_signature($uid), $sig)) {
        app_clear_remember_cookie();
        return;
    }
    $_SESSION['uid'] = $uid;
}

function app_strip_ai_label(string $value): string {
    $cleaned = preg_replace('/\s*\(sans\s*ia\)\s*/iu', ' ', $value);
    $cleaned = is_string($cleaned) ? $cleaned : $value;
    return trim(preg_replace('/\s{2,}/', ' ', $cleaned) ?? $cleaned);
}

function app_is_ajax_request(): bool {
    $header = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    return $header === 'xmlhttprequest';
}

function app_json_response(bool $ok, string $message = '', array $data = []): never {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => $ok,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function app_submission_guard(string $key, int $seconds = 3): bool {
    if (!isset($_SESSION['submit_guard']) || !is_array($_SESSION['submit_guard'])) {
        $_SESSION['submit_guard'] = [];
    }
    $now = time();
    $last = (int) ($_SESSION['submit_guard'][$key] ?? 0);
    if ($last > 0 && ($now - $last) < $seconds) {
        return false;
    }
    $_SESSION['submit_guard'][$key] = $now;
    return true;
}

function app_ranks(): array {
    return [
        ['name' => 'Petit Joueur', 'minPoints' => 0, 'icon' => '🧃', 'color' => 'text-gray-400'],
        ['name' => 'Apprenti Soiffard', 'minPoints' => 100, 'icon' => '🍺', 'color' => 'text-yellow-200'],
        ['name' => 'Barathonien', 'minPoints' => 300, 'icon' => '🍻', 'color' => 'text-orange-400'],
        ['name' => 'Mixologue Fou', 'minPoints' => 600, 'icon' => '🍹', 'color' => 'text-green-400'],
        ['name' => 'Roi de la Nuit', 'minPoints' => 1000, 'icon' => '🍸', 'color' => 'text-blue-400'],
        ['name' => 'Légende du Bar', 'minPoints' => 2000, 'icon' => '🍾', 'color' => 'text-purple-500'],
        ['name' => 'Sommelier du Chaos', 'minPoints' => 5000, 'icon' => '🍷', 'color' => 'text-red-500'],
        ['name' => 'Dieu de la Pinte', 'minPoints' => 10000, 'icon' => '⚡', 'color' => 'text-cyan-400'],
        ['name' => "L'Imbibe Supreme", 'minPoints' => 20000, 'icon' => '🧊', 'color' => 'text-pink-400'],
        ['name' => "L'Absolu Ethylique", 'minPoints' => 50000, 'icon' => '🌌', 'color' => 'text-violet-400'],
    ];
}

function app_get_rank(int $points): array {
    $ranks = app_ranks();
    usort($ranks, static fn(array $a, array $b): int => $b['minPoints'] <=> $a['minPoints']);
    foreach ($ranks as $rank) {
        if ($points >= (int) $rank['minPoints']) {
            return $rank;
        }
    }
    return app_ranks()[0];
}

function app_get_next_rank(int $points): ?array {
    $ranks = app_ranks();
    usort($ranks, static fn(array $a, array $b): int => $a['minPoints'] <=> $b['minPoints']);
    foreach ($ranks as $rank) {
        if ((int) $rank['minPoints'] > $points) {
            return $rank;
        }
    }
    return null;
}

function app_seed_avatar(string $seed): string {
    return 'https://api.dicebear.com/7.x/avataaars/svg?seed=' . rawurlencode($seed);
}

function app_allowed_profile_themes(): array {
    return ['midnight', 'sunset', 'emerald', 'aurora', 'obsidian'];
}

function app_allowed_name_styles(): array {
    return ['default', 'sunfire', 'aqua', 'royal', 'rainbow'];
}

function app_allowed_app_themes(): array {
    return ['neon', 'gold', 'ocean', 'crimson', 'frost'];
}

function app_generate_id(string $prefix): string {
    return $prefix . '_' . time() . '_' . bin2hex(random_bytes(4));
}

function app_default_data(): array {
    return [
        'users' => [
            [
                'id' => 'u1',
                'name' => 'Justin',
                'role' => ROLE_MEMBER,
                'points' => 0,
                'className' => 'Fetard',
                'bio' => '',
                'avatarUrl' => app_seed_avatar('Justin'),
                'inventory' => [],
                'achievements' => [],
                'password_hash' => null,
                'must_set_password' => true,
                'theme' => 'neon',
            ],
            [
                'id' => 'u2',
                'name' => 'Robin',
                'role' => ROLE_MEMBER,
                'points' => 0,
                'className' => 'Fetard',
                'bio' => '',
                'avatarUrl' => app_seed_avatar('Robin'),
                'inventory' => [],
                'achievements' => [],
                'password_hash' => null,
                'must_set_password' => true,
                'theme' => 'neon',
            ],
            [
                'id' => 'u3',
                'name' => 'Benjy',
                'role' => ROLE_MEMBER,
                'points' => 0,
                'className' => 'Fetard',
                'bio' => '',
                'avatarUrl' => app_seed_avatar('Benjy'),
                'inventory' => [],
                'achievements' => [],
                'password_hash' => null,
                'must_set_password' => true,
                'theme' => 'neon',
            ],
            [
                'id' => 'u4',
                'name' => 'Ruru',
                'role' => ROLE_MEMBER,
                'points' => 0,
                'className' => 'Fetard',
                'bio' => '',
                'avatarUrl' => app_seed_avatar('Ruru'),
                'inventory' => [],
                'achievements' => [],
                'password_hash' => null,
                'must_set_password' => true,
                'theme' => 'neon',
            ],
            [
                'id' => 'u5',
                'name' => 'Guilhem',
                'role' => ROLE_MEMBER,
                'points' => 0,
                'className' => 'Fetard',
                'bio' => '',
                'avatarUrl' => app_seed_avatar('Guilhem'),
                'inventory' => [],
                'achievements' => [],
                'password_hash' => null,
                'must_set_password' => true,
                'theme' => 'neon',
            ],
            [
                'id' => 'admin',
                'name' => 'Le Taulier (Admin)',
                'role' => ROLE_ADMIN,
                'points' => 0,
                'className' => 'Admin',
                'bio' => 'Gestionnaire de la confrerie',
                'avatarUrl' => 'https://api.dicebear.com/7.x/bottts/svg?seed=Admin',
                'inventory' => [],
                'achievements' => [],
                'password_hash' => password_hash('admin', PASSWORD_DEFAULT),
                'must_set_password' => false,
                'theme' => 'neon',
            ],
        ],
        'parties' => [],
        'posts' => [],
        'challenges' => [
            ['id' => 'c1', 'text' => 'Bois 3 gorgees sans les mains.', 'difficulty' => 'Facile'],
            ['id' => 'c2', 'text' => 'Fais trinquer 3 personnes inconnues.', 'difficulty' => 'Facile'],
            ['id' => 'c3', 'text' => 'Fais un toast dramatique de 20 secondes.', 'difficulty' => 'Facile'],
            ['id' => 'c4', 'text' => 'Imite une pub de boisson pendant 15 secondes.', 'difficulty' => 'Facile'],
            ['id' => 'c5', 'text' => 'Demande a quelqu’un son meilleur surnom de soiree.', 'difficulty' => 'Facile'],
            ['id' => 'c6', 'text' => 'Fais un cul sec (petit verre).', 'difficulty' => 'Moyen'],
            ['id' => 'c7', 'text' => 'Danse sans musique pendant 30 secondes.', 'difficulty' => 'Moyen'],
            ['id' => 'c8', 'text' => 'Raconte une anecdote cringe de 45 secondes.', 'difficulty' => 'Moyen'],
            ['id' => 'c9', 'text' => 'Parle avec une voix robot pendant 2 minutes.', 'difficulty' => 'Moyen'],
            ['id' => 'c10', 'text' => 'Fais un karaoke solo sur le refrain de ton choix.', 'difficulty' => 'Moyen'],
            ['id' => 'c11', 'text' => 'Laisse quelqu’un choisir ta boisson du prochain tour.', 'difficulty' => 'Moyen'],
            ['id' => 'c12', 'text' => 'Fais 15 squats avant de boire.', 'difficulty' => 'Moyen'],
            ['id' => 'c13', 'text' => 'Trouve un objet rouge et fais une story avec.', 'difficulty' => 'Moyen'],
            ['id' => 'c14', 'text' => 'Fais rire 3 personnes en moins de 2 minutes.', 'difficulty' => 'Moyen'],
            ['id' => 'c15', 'text' => 'Echange ton pseudo avec quelqu’un pour 10 minutes.', 'difficulty' => 'Moyen'],
            ['id' => 'c16', 'text' => 'Shot mystere choisi par la table.', 'difficulty' => 'Hardcore'],
            ['id' => 'c17', 'text' => 'Parle en rimant pendant 3 minutes.', 'difficulty' => 'Hardcore'],
            ['id' => 'c18', 'text' => 'Fais un mini stand-up de 1 minute.', 'difficulty' => 'Hardcore'],
            ['id' => 'c19', 'text' => 'Cul sec + 10 pompes.', 'difficulty' => 'Hardcore'],
            ['id' => 'c20', 'text' => 'Laisse le groupe choisir ton prochain defi.', 'difficulty' => 'Hardcore'],
            ['id' => 'c21', 'text' => 'Fais la meilleure imitation d’un prof.', 'difficulty' => 'Moyen'],
            ['id' => 'c22', 'text' => 'Bois en gardant les yeux fermes.', 'difficulty' => 'Facile'],
            ['id' => 'c23', 'text' => 'Reconstitue une scene de film au hasard.', 'difficulty' => 'Moyen'],
            ['id' => 'c24', 'text' => 'Invente un cocktail imaginaire et vend-le.', 'difficulty' => 'Moyen'],
            ['id' => 'c25', 'text' => 'Fais un compliment sincere a 4 personnes.', 'difficulty' => 'Facile'],
            ['id' => 'c26', 'text' => 'Mime un animal jusqu’a ce qu’on devine.', 'difficulty' => 'Facile'],
            ['id' => 'c27', 'text' => 'Laisse ton voisin ecrire ta bio pour 10 min.', 'difficulty' => 'Moyen'],
            ['id' => 'c28', 'text' => 'Parie un shot sur un pierre-feuille-ciseaux.', 'difficulty' => 'Hardcore'],
            ['id' => 'c29', 'text' => 'Bois une gorgee a chaque fois que tu ris (5 min).', 'difficulty' => 'Hardcore'],
            ['id' => 'c30', 'text' => 'Tu dois finir ta phrase en chantant (10 min).', 'difficulty' => 'Moyen'],
            ['id' => 'c31', 'text' => 'Fais un tour de table en mode presentateur TV.', 'difficulty' => 'Moyen'],
            ['id' => 'c32', 'text' => 'Crie “sante” dans 3 langues.', 'difficulty' => 'Facile'],
            ['id' => 'c33', 'text' => 'Raconte ton reve le plus bizarre.', 'difficulty' => 'Facile'],
            ['id' => 'c34', 'text' => 'Defi mime cocktail: les autres devinent.', 'difficulty' => 'Moyen'],
            ['id' => 'c35', 'text' => 'Change de place toutes les 2 minutes (10 min).', 'difficulty' => 'Hardcore'],
            ['id' => 'c36', 'text' => 'Fais un selfie de groupe le plus chaotique possible.', 'difficulty' => 'Facile'],
            ['id' => 'c37', 'text' => 'Donne un surnom a chacun de la table.', 'difficulty' => 'Moyen'],
            ['id' => 'c38', 'text' => 'Fais un plan de soiree absurde en 30 secondes.', 'difficulty' => 'Moyen'],
            ['id' => 'c39', 'text' => 'Prends la pose statue pendant 45 secondes.', 'difficulty' => 'Facile'],
            ['id' => 'c40', 'text' => 'Tu perds: shot. Tu gagnes: shot offert (mini-jeu).', 'difficulty' => 'Hardcore'],
            ['id' => 'c41', 'text' => 'Fais 20 secondes de moonwalk improvise.', 'difficulty' => 'Moyen'],
            ['id' => 'c42', 'text' => 'Discours de remerciement pour une “victoire” imaginaire.', 'difficulty' => 'Moyen'],
            ['id' => 'c43', 'text' => 'Raconte 2 verites + 1 mensonge sur ta semaine.', 'difficulty' => 'Facile'],
            ['id' => 'c44', 'text' => 'Prends un accent aleatoire pendant 5 minutes.', 'difficulty' => 'Hardcore'],
            ['id' => 'c45', 'text' => 'Defi “aucun mot anglais” pendant 10 minutes.', 'difficulty' => 'Hardcore'],
            ['id' => 'c46', 'text' => 'Fais une pub pour l’eau en mode epique.', 'difficulty' => 'Facile'],
            ['id' => 'c47', 'text' => 'Danse synchronisee avec un binome 20 secondes.', 'difficulty' => 'Moyen'],
            ['id' => 'c48', 'text' => 'Fais un check original avec 5 personnes.', 'difficulty' => 'Facile'],
            ['id' => 'c49', 'text' => 'Shot si tu rates une devinette du groupe.', 'difficulty' => 'Hardcore'],
            ['id' => 'c50', 'text' => 'Tu dois parler en chuchotant pendant 3 minutes.', 'difficulty' => 'Moyen'],
            ['id' => 'c51', 'text' => 'Imite un DJ pendant 30 secondes.', 'difficulty' => 'Facile'],
            ['id' => 'c52', 'text' => 'Fais une mini interview de 2 personnes.', 'difficulty' => 'Facile'],
            ['id' => 'c53', 'text' => 'Fais deviner un film juste avec des gestes.', 'difficulty' => 'Moyen'],
            ['id' => 'c54', 'text' => 'Bois uniquement a la paille au prochain verre.', 'difficulty' => 'Moyen'],
            ['id' => 'c55', 'text' => 'Defi vitesse: finis ton histoire en 20 secondes.', 'difficulty' => 'Moyen'],
            ['id' => 'c56', 'text' => 'Tu choisis un “mot interdit” pour 10 min.', 'difficulty' => 'Hardcore'],
            ['id' => 'c57', 'text' => 'Rime avec chaque prenom de la table.', 'difficulty' => 'Hardcore'],
            ['id' => 'c58', 'text' => 'Fais un compliment absurde mais stylé.', 'difficulty' => 'Facile'],
            ['id' => 'c59', 'text' => 'Cache un “easter egg” dans une photo de groupe.', 'difficulty' => 'Moyen'],
            ['id' => 'c60', 'text' => 'Shot final si personne ne rigole a ta blague.', 'difficulty' => 'Hardcore'],
        ],
        'settings' => [
            'isMapEnabled' => true,
        ],
        'activity' => [],
        'notifications' => [],
        'achievementLibrary' => [],
    ];
}

function app_normalize_item(array $item): array {
    return [
        'id' => (string) ($item['id'] ?? app_generate_id('item')),
        'name' => trim((string) ($item['name'] ?? 'Objet')),
        'description' => trim((string) ($item['description'] ?? 'Objet mysterieux')),
        'rarity' => trim((string) ($item['rarity'] ?? 'Commune')),
        'imageUrl' => (string) ($item['imageUrl'] ?? ''),
        'stats' => (string) ($item['stats'] ?? 'Special'),
    ];
}

function app_normalize_achievement(array $achievement): array {
    return [
        'id' => (string) ($achievement['id'] ?? app_generate_id('ach')),
        'name' => trim((string) ($achievement['name'] ?? 'Succes')),
        'description' => trim((string) ($achievement['description'] ?? 'Succes debloque')),
        'icon' => trim((string) ($achievement['icon'] ?? '🏆')),
        'unlockedAt' => (int) ($achievement['unlockedAt'] ?? time()),
    ];
}

function app_normalize_user(array $user): array {
    $role = strtoupper((string) ($user['role'] ?? ROLE_MEMBER));
    if ($role !== ROLE_ADMIN) {
        $role = ROLE_MEMBER;
    }

    $name = trim((string) ($user['name'] ?? 'Membre'));
    if ($name === '') {
        $name = 'Membre';
    }

    $legacyPassword = (string) ($user['password'] ?? '');
    $passwordHash = $user['password_hash'] ?? null;
    if ($passwordHash === null && $legacyPassword !== '') {
        $passwordHash = password_hash($legacyPassword, PASSWORD_DEFAULT);
    }

    $inventory = [];
    if (isset($user['inventory']) && is_array($user['inventory'])) {
        foreach ($user['inventory'] as $item) {
            if (is_array($item)) {
                $inventory[] = app_normalize_item($item);
            }
        }
    }

    $achievements = [];
    if (isset($user['achievements']) && is_array($user['achievements'])) {
        foreach ($user['achievements'] as $achievement) {
            if (is_array($achievement)) {
                $achievements[] = app_normalize_achievement($achievement);
            }
        }
    }

    $theme = (string) ($user['theme'] ?? 'neon');
    if (!in_array($theme, app_allowed_app_themes(), true)) {
        $theme = 'neon';
    }

    $profileTheme = (string) ($user['profileTheme'] ?? 'midnight');
    if (!in_array($profileTheme, app_allowed_profile_themes(), true)) {
        $profileTheme = 'midnight';
    }

    $nameStyle = (string) ($user['nameStyle'] ?? 'default');
    if (!in_array($nameStyle, app_allowed_name_styles(), true)) {
        $nameStyle = 'default';
    }

    $mustSetPassword = (bool) ($user['must_set_password'] ?? false);
    if ($role === ROLE_MEMBER && empty($passwordHash)) {
        $mustSetPassword = true;
    }

    return [
        'id' => (string) ($user['id'] ?? app_generate_id('u')),
        'name' => $name,
        'role' => $role,
        'points' => max(0, (int) ($user['points'] ?? 0)),
        'className' => trim((string) ($user['className'] ?? 'Fetard')),
        'bio' => trim((string) ($user['bio'] ?? '')),
        'avatarUrl' => (string) ($user['avatarUrl'] ?? app_seed_avatar($name)),
        'inventory' => $inventory,
        'achievements' => $achievements,
        'password_hash' => is_string($passwordHash) && $passwordHash !== '' ? $passwordHash : null,
        'must_set_password' => $mustSetPassword,
        'theme' => $theme,
        'profileTheme' => $profileTheme,
        'nameStyle' => $nameStyle,
        'profileTitle' => app_cut(trim((string) ($user['profileTitle'] ?? '')), 40),
        'profileMotto' => app_cut(trim((string) ($user['profileMotto'] ?? '')), 110),
        'favoriteDrink' => app_cut(trim((string) ($user['favoriteDrink'] ?? '')), 32),
        'bannerUrl' => app_cut(trim((string) ($user['bannerUrl'] ?? '')), 300),
    ];
}

function app_normalize_party(array $party): array {
    $lat = isset($party['lat']) && is_numeric($party['lat']) ? (float) $party['lat'] : null;
    $lng = isset($party['lng']) && is_numeric($party['lng']) ? (float) $party['lng'] : null;

    return [
        'id' => (string) ($party['id'] ?? app_generate_id('party')),
        'name' => trim((string) ($party['name'] ?? 'Soiree')),
        'date' => (string) ($party['date'] ?? date('Y-m-d')),
        'locationName' => trim((string) ($party['locationName'] ?? 'Lieu inconnu')),
        'coverUrl' => (string) ($party['coverUrl'] ?? 'logo.png'),
        'lat' => $lat,
        'lng' => $lng,
        'createdBy' => (string) ($party['createdBy'] ?? 'admin'),
    ];
}

function app_normalize_post_media(array $post): array {
    $media = [];
    if (isset($post['media']) && is_array($post['media'])) {
        foreach ($post['media'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $type = strtolower(trim((string) ($entry['type'] ?? '')));
            $url = trim((string) ($entry['url'] ?? ''));
            if ($url === '' || !in_array($type, ['image', 'video'], true)) {
                continue;
            }
            $media[] = ['type' => $type, 'url' => $url];
        }
    }

    if ($media === []) {
        $legacyImage = trim((string) ($post['imageUrl'] ?? ''));
        if ($legacyImage !== '') {
            $media[] = ['type' => 'image', 'url' => $legacyImage];
        }
    }

    return array_slice($media, 0, 8);
}

function app_normalize_post(array $post): array {
    $media = app_normalize_post_media($post);
    $likes = [];
    if (isset($post['likes']) && is_array($post['likes'])) {
        foreach ($post['likes'] as $uid) {
            if (is_string($uid) && $uid !== '') {
                $likes[] = $uid;
            }
        }
    }

    $firstImage = '';
    foreach ($media as $item) {
        if (($item['type'] ?? '') === 'image') {
            $firstImage = (string) ($item['url'] ?? '');
            break;
        }
    }

    return [
        'id' => (string) ($post['id'] ?? app_generate_id('post')),
        'userId' => (string) ($post['userId'] ?? ''),
        'partyId' => (string) ($post['partyId'] ?? ''),
        'imageUrl' => $firstImage !== '' ? $firstImage : (string) ($post['imageUrl'] ?? ''),
        'media' => $media,
        'description' => trim((string) ($post['description'] ?? '')),
        'pointsAwarded' => (int) ($post['pointsAwarded'] ?? 0),
        'gmComment' => app_strip_ai_label((string) ($post['gmComment'] ?? 'Post publie.')),
        'timestamp' => (int) ($post['timestamp'] ?? time()),
        'likes' => array_values(array_unique($likes)),
    ];
}

function app_normalize_challenge(array $challenge): array {
    $difficulty = trim((string) ($challenge['difficulty'] ?? 'Moyen'));
    if (!in_array($difficulty, ['Facile', 'Moyen', 'Hardcore'], true)) {
        $difficulty = 'Moyen';
    }

    return [
        'id' => (string) ($challenge['id'] ?? app_generate_id('challenge')),
        'text' => trim((string) ($challenge['text'] ?? 'Defi mystere')),
        'difficulty' => $difficulty,
    ];
}

function app_normalize_notification(array $notification): array {
    return [
        'id' => (string) ($notification['id'] ?? app_generate_id('notif')),
        'toUserId' => (string) ($notification['toUserId'] ?? ''),
        'type' => app_cut(trim((string) ($notification['type'] ?? 'info')), 20),
        'title' => app_cut(trim((string) ($notification['title'] ?? 'Notification')), 80),
        'body' => app_cut(trim((string) ($notification['body'] ?? '')), 220),
        'createdAt' => (int) ($notification['createdAt'] ?? time()),
        'readAt' => isset($notification['readAt']) ? (int) $notification['readAt'] : null,
    ];
}

function app_normalize_data(array $input): array {
    $default = app_default_data();

    $users = [];
    if (isset($input['users']) && is_array($input['users'])) {
        foreach ($input['users'] as $user) {
            if (is_array($user)) {
                $users[] = app_normalize_user($user);
            }
        }
    }
    if ($users === []) {
        $users = $default['users'];
    }

    $hasAdmin = false;
    foreach ($users as $user) {
        if (($user['role'] ?? '') === ROLE_ADMIN) {
            $hasAdmin = true;
            break;
        }
    }
    if (!$hasAdmin) {
        foreach ($default['users'] as $defaultUser) {
            if ($defaultUser['role'] === ROLE_ADMIN) {
                $users[] = $defaultUser;
                break;
            }
        }
    }

    $parties = [];
    if (isset($input['parties']) && is_array($input['parties'])) {
        foreach ($input['parties'] as $party) {
            if (is_array($party)) {
                $parties[] = app_normalize_party($party);
            }
        }
    }
    if ($parties === []) {
        $parties = $default['parties'];
    }

    $posts = [];
    if (isset($input['posts']) && is_array($input['posts'])) {
        foreach ($input['posts'] as $post) {
            if (is_array($post)) {
                $posts[] = app_normalize_post($post);
            }
        }
    }

    $challenges = [];
    if (isset($input['challenges']) && is_array($input['challenges'])) {
        foreach ($input['challenges'] as $challenge) {
            if (is_array($challenge)) {
                $challenges[] = app_normalize_challenge($challenge);
            }
        }
    }
    if ($challenges === []) {
        $challenges = $default['challenges'];
    }
    $existingChallengeTexts = [];
    foreach ($challenges as $entry) {
        $txt = trim((string) ($entry['text'] ?? ''));
        if ($txt !== '') {
            $key = function_exists('mb_strtolower') ? mb_strtolower($txt) : strtolower($txt);
            $existingChallengeTexts[$key] = true;
        }
    }
    foreach ($default['challenges'] as $seedChallenge) {
        $txt = trim((string) ($seedChallenge['text'] ?? ''));
        if ($txt === '') {
            continue;
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($txt) : strtolower($txt);
        if (isset($existingChallengeTexts[$key])) {
            continue;
        }
        $challenges[] = app_normalize_challenge($seedChallenge);
        $existingChallengeTexts[$key] = true;
    }

    $settings = ['isMapEnabled' => true];
    if (isset($input['settings']) && is_array($input['settings'])) {
        $settings['isMapEnabled'] = (bool) ($input['settings']['isMapEnabled'] ?? true);
    }

    $activity = [];
    if (isset($input['activity']) && is_array($input['activity'])) {
        foreach ($input['activity'] as $line) {
            if (is_array($line)) {
                $activity[] = [
                    'id' => (string) ($line['id'] ?? app_generate_id('act')),
                    'type' => (string) ($line['type'] ?? 'event'),
                    'by' => (string) ($line['by'] ?? ''),
                    'target' => (string) ($line['target'] ?? ''),
                    'delta' => (int) ($line['delta'] ?? 0),
                    'reason' => (string) ($line['reason'] ?? ''),
                    'timestamp' => (int) ($line['timestamp'] ?? time()),
                ];
            }
        }
    }

    $notifications = [];
    if (isset($input['notifications']) && is_array($input['notifications'])) {
        foreach ($input['notifications'] as $notification) {
            if (is_array($notification)) {
                $notifications[] = app_normalize_notification($notification);
            }
        }
    }
    if (isset($input['messages']) && is_array($input['messages'])) {
        foreach ($input['messages'] as $legacyMessage) {
            if (!is_array($legacyMessage)) {
                continue;
            }
            $toUserId = trim((string) ($legacyMessage['toUserId'] ?? ''));
            if ($toUserId === '') {
                continue;
            }
            $notifications[] = app_normalize_notification([
                'toUserId' => $toUserId,
                'type' => 'message',
                'title' => 'Message',
                'body' => (string) ($legacyMessage['text'] ?? ''),
                'createdAt' => (int) ($legacyMessage['timestamp'] ?? time()),
                'readAt' => null,
            ]);
        }
    }

    $achievementLibrary = [];
    if (isset($input['achievementLibrary']) && is_array($input['achievementLibrary'])) {
        foreach ($input['achievementLibrary'] as $achievement) {
            if (is_array($achievement)) {
                $achievementLibrary[] = app_normalize_achievement($achievement);
            }
        }
    }

    return [
        'users' => $users,
        'parties' => $parties,
        'posts' => $posts,
        'challenges' => $challenges,
        'settings' => $settings,
        'activity' => $activity,
        'notifications' => $notifications,
        'achievementLibrary' => $achievementLibrary,
    ];
}

function app_ensure_storage(): void {
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0775, true);
    }
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0775, true);
    }
    if (!file_exists(DATA_FILE)) {
        file_put_contents(DATA_FILE, json_encode(app_default_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

function app_load_data(): array {
    app_ensure_storage();
    $raw = file_get_contents(DATA_FILE);
    if ($raw === false || trim($raw) === '') {
        return app_default_data();
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return app_default_data();
    }

    return app_normalize_data($decoded);
}

function app_save_data(array $data): void {
    app_ensure_storage();
    $normalized = app_normalize_data($data);

    $fp = fopen(DATA_FILE, 'c+');
    if ($fp === false) {
        throw new RuntimeException('Impossible d\'ouvrir le fichier de donnees.');
    }

    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function app_flash(?string $type = null, ?string $message = null): ?array {
    if ($type !== null && $message !== null) {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
        return null;
    }

    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function app_redirect(array $query = []): never {
    $base = basename(__FILE__);
    $url = $base;
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }
    header('Location: ' . $url);
    exit;
}

function app_find_user_index(array $data, string $userId): int {
    foreach ($data['users'] as $index => $user) {
        if (($user['id'] ?? '') === $userId) {
            return $index;
        }
    }
    return -1;
}

function app_find_party_index(array $data, string $partyId): int {
    foreach ($data['parties'] as $index => $party) {
        if (($party['id'] ?? '') === $partyId) {
            return $index;
        }
    }
    return -1;
}

function app_find_post_index(array $data, string $postId): int {
    foreach ($data['posts'] as $index => $post) {
        if (($post['id'] ?? '') === $postId) {
            return $index;
        }
    }
    return -1;
}

function app_current_user_id(): ?string {
    $uid = $_SESSION['uid'] ?? null;
    if (!is_string($uid) || $uid === '') {
        return null;
    }
    return $uid;
}

function app_current_user(array $data): ?array {
    $uid = app_current_user_id();
    if ($uid === null) {
        return null;
    }
    $idx = app_find_user_index($data, $uid);
    if ($idx < 0) {
        return null;
    }
    return $data['users'][$idx];
}

function app_is_admin(?array $user): bool {
    return is_array($user) && (($user['role'] ?? '') === ROLE_ADMIN);
}

function app_can_access_admin(?array $user): bool {
    if (!is_array($user)) {
        return false;
    }
    if (($user['role'] ?? '') === ROLE_ADMIN) {
        return true;
    }
    return (($user['id'] ?? '') === 'u5') && !empty($_SESSION['admin_mode_enabled']);
}

function app_store_uploaded_image(string $fieldName): ?string {
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return null;
    }

    $file = $_FILES[$fieldName];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
        return null;
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return null;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) finfo_file($finfo, $tmpName) : '';
    if ($finfo) {
        finfo_close($finfo);
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/avif' => 'avif',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
    ];

    if (!isset($allowed[$mime])) {
        return null;
    }

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0775, true);
    }

    $filename = 'img_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $targetPath = UPLOAD_DIR . '/' . $filename;

    if (!move_uploaded_file($tmpName, $targetPath)) {
        return null;
    }

    return 'uploads/' . $filename;
}

function app_detect_uploaded_mime(string $tmpName): string {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) finfo_file($finfo, $tmpName) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    return $mime;
}

function app_store_uploaded_media_batch(string $fieldName, int $maxFiles = 8): array {
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return [
            'media' => [],
            'selected' => 0,
            'tooLarge' => 0,
            'unsupported' => 0,
            'failed' => 0,
            'truncated' => false,
        ];
    }

    $file = $_FILES[$fieldName];
    $entries = [];
    if (is_array($file['name'] ?? null)) {
        foreach (($file['name'] ?? []) as $idx => $name) {
            $entries[] = [
                'error' => (int) (($file['error'][$idx] ?? UPLOAD_ERR_NO_FILE)),
                'tmp_name' => (string) (($file['tmp_name'][$idx] ?? '')),
                'name' => (string) $name,
            ];
        }
    } else {
        $entries[] = [
            'error' => (int) (($file['error'] ?? UPLOAD_ERR_NO_FILE)),
            'tmp_name' => (string) (($file['tmp_name'] ?? '')),
            'name' => (string) (($file['name'] ?? '')),
        ];
    }

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0775, true);
    }

    $allowedByMime = [
        'image/jpeg' => ['type' => 'image', 'ext' => 'jpg'],
        'image/png' => ['type' => 'image', 'ext' => 'png'],
        'image/webp' => ['type' => 'image', 'ext' => 'webp'],
        'image/gif' => ['type' => 'image', 'ext' => 'gif'],
        'image/avif' => ['type' => 'image', 'ext' => 'avif'],
        'image/heic' => ['type' => 'image', 'ext' => 'heic'],
        'image/heif' => ['type' => 'image', 'ext' => 'heif'],
        'video/mp4' => ['type' => 'video', 'ext' => 'mp4'],
        'video/webm' => ['type' => 'video', 'ext' => 'webm'],
        'video/quicktime' => ['type' => 'video', 'ext' => 'mov'],
        'video/x-m4v' => ['type' => 'video', 'ext' => 'm4v'],
        'video/3gpp' => ['type' => 'video', 'ext' => '3gp'],
    ];
    $allowedByExt = [
        'jpg' => ['type' => 'image', 'ext' => 'jpg'],
        'jpeg' => ['type' => 'image', 'ext' => 'jpg'],
        'png' => ['type' => 'image', 'ext' => 'png'],
        'webp' => ['type' => 'image', 'ext' => 'webp'],
        'gif' => ['type' => 'image', 'ext' => 'gif'],
        'avif' => ['type' => 'image', 'ext' => 'avif'],
        'heic' => ['type' => 'image', 'ext' => 'heic'],
        'heif' => ['type' => 'image', 'ext' => 'heif'],
        'mp4' => ['type' => 'video', 'ext' => 'mp4'],
        'webm' => ['type' => 'video', 'ext' => 'webm'],
        'mov' => ['type' => 'video', 'ext' => 'mov'],
        'm4v' => ['type' => 'video', 'ext' => 'm4v'],
        '3gp' => ['type' => 'video', 'ext' => '3gp'],
    ];

    $stored = [];
    $selected = 0;
    $tooLarge = 0;
    $unsupported = 0;
    $failed = 0;
    $truncated = false;

    foreach ($entries as $entry) {
        if (count($stored) >= $maxFiles) {
            $truncated = true;
            break;
        }
        if (($entry['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $selected++;

        $errorCode = (int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE);
        if (in_array($errorCode, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            $tooLarge++;
            continue;
        }
        if (in_array($errorCode, [UPLOAD_ERR_PARTIAL, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION], true)) {
            $failed++;
            continue;
        }
        if ($errorCode !== UPLOAD_ERR_OK) {
            $failed++;
            continue;
        }

        $tmpName = (string) ($entry['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            $failed++;
            continue;
        }

        $mime = app_detect_uploaded_mime($tmpName);
        $meta = $allowedByMime[$mime] ?? null;
        if ($meta === null) {
            $ext = strtolower(pathinfo((string) ($entry['name'] ?? ''), PATHINFO_EXTENSION));
            if ($ext !== '' && isset($allowedByExt[$ext])) {
                $meta = $allowedByExt[$ext];
            }
        }
        if ($meta === null) {
            $unsupported++;
            continue;
        }

        $prefix = ($meta['type'] === 'video') ? 'vid_' : 'img_';
        $filename = $prefix . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $meta['ext'];
        $targetPath = UPLOAD_DIR . '/' . $filename;
        if (!move_uploaded_file($tmpName, $targetPath)) {
            $failed++;
            continue;
        }

        $stored[] = [
            'type' => (string) $meta['type'],
            'url' => 'uploads/' . $filename,
        ];
    }

    return [
        'media' => $stored,
        'selected' => $selected,
        'tooLarge' => $tooLarge,
        'unsupported' => $unsupported,
        'failed' => $failed,
        'truncated' => $truncated,
    ];
}

function app_compute_post_points(string $description, array $media): array {
    $len = function_exists('mb_strlen') ? mb_strlen($description) : strlen($description);
    $mediaCount = count($media);
    $videoCount = 0;
    foreach ($media as $entry) {
        if (($entry['type'] ?? '') === 'video') {
            $videoCount++;
        }
    }
    $imageCount = max(0, $mediaCount - $videoCount);

    $descBonus = (int) min(10, floor((float) $len / 40) * 2 + ($len > 0 ? 2 : 0));
    $mediaBonus = min(22, ($imageCount * 3) + ($videoCount * 5));
    $points = max(2, min(45, 4 + $descBonus + $mediaBonus + random_int(0, 6)));

    $comments = [
        'Style valide, bon dossier.',
        'Grosse energie, continue comme ca.',
        'Post solide, points accordes.',
        'Belle contribution a la soiree.',
        'Bon niveau, ca monte.',
    ];

    return [
        'points' => $points,
        'comment' => $comments[array_rand($comments)],
    ];
}

function app_safe_page(string $candidate, bool $isAdmin): string {
    $allowed = ['dashboard', 'parties', 'feed', 'rankings', 'map'];
    if ($isAdmin) {
        $allowed[] = 'admin';
    }
    return in_array($candidate, $allowed, true) ? $candidate : 'dashboard';
}

function app_sort_posts(array $posts): array {
    usort($posts, static fn(array $a, array $b): int => ((int) ($b['timestamp'] ?? 0)) <=> ((int) ($a['timestamp'] ?? 0)));
    return $posts;
}

function app_user_name_map(array $users): array {
    $map = [];
    foreach ($users as $user) {
        $map[$user['id']] = $user;
    }
    return $map;
}

function app_random_challenge(array $challenges): string {
    if ($challenges === []) {
        return 'Aucun defi disponible.';
    }
    if (!isset($_SESSION['challenge_bag']) || !is_array($_SESSION['challenge_bag']) || $_SESSION['challenge_bag'] === []) {
        $bag = array_keys($challenges);
        shuffle($bag);
        $_SESSION['challenge_bag'] = $bag;
    }
    $index = array_pop($_SESSION['challenge_bag']);
    if (!is_int($index) || !isset($challenges[$index])) {
        $entry = $challenges[array_rand($challenges)];
        return (string) ($entry['text'] ?? 'Defi surprise');
    }
    return (string) ($challenges[$index]['text'] ?? 'Defi surprise');
}

function app_push_notification(array &$data, string $toUserId, string $type, string $title, string $body): void {
    if (!isset($data['notifications']) || !is_array($data['notifications'])) {
        $data['notifications'] = [];
    }
    $data['notifications'][] = app_normalize_notification([
        'id' => app_generate_id('notif'),
        'toUserId' => $toUserId,
        'type' => $type,
        'title' => $title,
        'body' => $body,
        'createdAt' => time(),
        'readAt' => null,
    ]);
}

function app_collect_and_mark_notifications(array &$data, string $userId, int $limit = 20): array {
    if (!isset($data['notifications']) || !is_array($data['notifications'])) {
        return [];
    }
    $out = [];
    $changed = false;
    for ($i = count($data['notifications']) - 1; $i >= 0; $i--) {
        $entry = $data['notifications'][$i] ?? null;
        if (!is_array($entry)) {
            continue;
        }
        if ((string) ($entry['toUserId'] ?? '') !== $userId) {
            continue;
        }
        if (($entry['readAt'] ?? null) !== null) {
            continue;
        }
        $data['notifications'][$i]['readAt'] = time();
        $changed = true;
        $out[] = app_normalize_notification($data['notifications'][$i]);
        if (count($out) >= $limit) {
            break;
        }
    }
    if ($changed) {
        app_save_data($data);
    }
    return $out;
}

function app_require_post_csrf_or_fail(): void {
    $sessionToken = (string) ($_SESSION['csrf'] ?? '');
    $requestToken = (string) ($_POST['csrf'] ?? '');
    if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
        http_response_code(419);
        echo 'Session expirée, recharge la page.';
        exit;
    }
}

function app_get_theme_class(?array $user): string {
    $theme = (string) ($user['theme'] ?? 'neon');
    if ($theme === 'gold') {
        return 'theme-gold';
    }
    if ($theme === 'ocean') {
        return 'theme-ocean';
    }
    if ($theme === 'crimson') {
        return 'theme-crimson';
    }
    if ($theme === 'frost') {
        return 'theme-frost';
    }
    return 'theme-neon';
}

function app_get_profile_theme_class(?array $user): string {
    $theme = (string) ($user['profileTheme'] ?? 'midnight');
    if (!in_array($theme, app_allowed_profile_themes(), true)) {
        $theme = 'midnight';
    }
    return 'profile-theme-' . $theme;
}

function app_get_name_style_class(?array $user): string {
    $style = (string) ($user['nameStyle'] ?? 'default');
    if (!in_array($style, app_allowed_name_styles(), true)) {
        $style = 'default';
    }
    return 'name-style-' . $style;
}

app_ensure_storage();
$data = app_load_data();
app_restore_uid_from_cookie($data);
app_refresh_session_cookie();

if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

$rawCurrentUser = app_current_user($data);
if (app_current_user_id() !== null && $rawCurrentUser === null) {
    unset($_SESSION['uid']);
    app_clear_remember_cookie();
}

$requestMethod = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($requestMethod === 'POST') {
    app_require_post_csrf_or_fail();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'login') {
        $userId = trim((string) ($_POST['user_id'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $idx = app_find_user_index($data, $userId);
        if ($idx < 0) {
            app_flash('error', 'Compte introuvable.');
            app_redirect();
        }

        $user = $data['users'][$idx];
        $needsPassword = !empty($user['password_hash']) || ($user['role'] === ROLE_ADMIN);
        if ($needsPassword) {
            $hash = (string) ($user['password_hash'] ?? '');
            if ($hash === '' || !password_verify($password, $hash)) {
                app_flash('error', 'Mot de passe incorrect.');
                app_redirect();
            }
        }

        $_SESSION['uid'] = $user['id'];
        app_set_remember_cookie((string) $user['id']);
        if (($user['id'] ?? '') !== 'u5') {
            $_SESSION['admin_mode_enabled'] = false;
        }
        if ($user['role'] === ROLE_MEMBER && empty($user['password_hash'])) {
            app_flash('info', 'Premiere connexion: pense a definir ton mot de passe dans Parametres.');
        } else {
            app_flash('success', 'Connexion reussie.');
        }
        app_redirect(['page' => 'dashboard']);
    }

    if ($action === 'logout') {
        unset($_SESSION['uid']);
        $_SESSION['admin_mode_enabled'] = false;
        app_clear_remember_cookie();
        app_flash('info', 'Deconnexion ok.');
        app_redirect();
    }

    $currentUser = app_current_user($data);
    if ($currentUser === null) {
        app_flash('error', 'Connexion requise.');
        app_redirect();
    }

    $currentUserIndex = app_find_user_index($data, (string) $currentUser['id']);
    if ($currentUserIndex < 0) {
        app_flash('error', 'Session invalide.');
        app_redirect();
    }

    if ($action === 'set_password') {
        $newPassword = trim((string) ($_POST['new_password'] ?? ''));
        if (strlen($newPassword) < 4) {
            if (app_is_ajax_request()) {
                app_json_response(false, 'Mot de passe trop court (min 4).');
            }
            app_flash('error', 'Mot de passe trop court (min 4).');
            app_redirect(['page' => 'dashboard']);
        }

        $data['users'][$currentUserIndex]['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        $data['users'][$currentUserIndex]['must_set_password'] = false;
        app_save_data($data);
        if (app_is_ajax_request()) {
            app_json_response(true, 'Mot de passe enregistre.');
        }
        app_flash('success', 'Mot de passe enregistre.');
        app_redirect(['page' => 'dashboard']);
    }

    if ($action === 'update_profile') {
        $name = trim((string) ($_POST['name'] ?? $currentUser['name']));
        $className = trim((string) ($_POST['class_name'] ?? $currentUser['className']));
        $bio = trim((string) ($_POST['bio'] ?? $currentUser['bio']));
        $theme = trim((string) ($_POST['theme'] ?? $currentUser['theme']));
        $profileTheme = trim((string) ($_POST['profile_theme'] ?? ($currentUser['profileTheme'] ?? 'midnight')));
        $nameStyle = trim((string) ($_POST['name_style'] ?? ($currentUser['nameStyle'] ?? 'default')));
        $profileTitle = trim((string) ($_POST['profile_title'] ?? ($currentUser['profileTitle'] ?? '')));
        $profileMotto = trim((string) ($_POST['profile_motto'] ?? ($currentUser['profileMotto'] ?? '')));
        $favoriteDrink = trim((string) ($_POST['favorite_drink'] ?? ($currentUser['favoriteDrink'] ?? '')));
        $bannerUrl = trim((string) ($_POST['banner_url'] ?? ($currentUser['bannerUrl'] ?? '')));
        $password = trim((string) ($_POST['new_password'] ?? ''));
        $avatarUrlInput = trim((string) ($_POST['avatar_url'] ?? ''));

        if ($name === '') {
            $name = (string) $currentUser['name'];
        }
        if ($className === '') {
            $className = 'Fetard';
        }
        if (!in_array($theme, app_allowed_app_themes(), true)) {
            $theme = 'neon';
        }
        if (!in_array($profileTheme, app_allowed_profile_themes(), true)) {
            $profileTheme = 'midnight';
        }
        if (!in_array($nameStyle, app_allowed_name_styles(), true)) {
            $nameStyle = 'default';
        }

        $avatarPath = app_store_uploaded_image('avatar_file');

        $data['users'][$currentUserIndex]['name'] = app_cut($name, 40);
        $data['users'][$currentUserIndex]['className'] = app_cut($className, 40);
        $data['users'][$currentUserIndex]['bio'] = app_cut($bio, 180);
        $data['users'][$currentUserIndex]['theme'] = $theme;
        $data['users'][$currentUserIndex]['profileTheme'] = $profileTheme;
        $data['users'][$currentUserIndex]['nameStyle'] = $nameStyle;
        $data['users'][$currentUserIndex]['profileTitle'] = app_cut($profileTitle, 40);
        $data['users'][$currentUserIndex]['profileMotto'] = app_cut($profileMotto, 110);
        $data['users'][$currentUserIndex]['favoriteDrink'] = app_cut($favoriteDrink, 32);
        $data['users'][$currentUserIndex]['bannerUrl'] = filter_var($bannerUrl, FILTER_VALIDATE_URL) ? $bannerUrl : '';
        if ($avatarPath !== null) {
            $data['users'][$currentUserIndex]['avatarUrl'] = $avatarPath;
        } elseif ($avatarUrlInput !== '' && filter_var($avatarUrlInput, FILTER_VALIDATE_URL)) {
            $data['users'][$currentUserIndex]['avatarUrl'] = $avatarUrlInput;
        }

        if ($password !== '') {
            if (strlen($password) < 4) {
                if (app_is_ajax_request()) {
                    app_json_response(false, 'Nouveau mot de passe trop court (min 4).');
                }
                app_flash('error', 'Nouveau mot de passe trop court (min 4).');
                app_redirect(['page' => 'dashboard']);
            }
            $data['users'][$currentUserIndex]['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $data['users'][$currentUserIndex]['must_set_password'] = false;
        }

        app_save_data($data);
        if (app_is_ajax_request()) {
            $updated = $data['users'][$currentUserIndex];
            app_json_response(true, 'Profil mis a jour.', [
                'user' => [
                    'name' => (string) $updated['name'],
                    'className' => (string) $updated['className'],
                    'bio' => (string) $updated['bio'],
                    'avatarUrl' => (string) $updated['avatarUrl'],
                    'theme' => (string) $updated['theme'],
                    'profileTheme' => (string) ($updated['profileTheme'] ?? 'midnight'),
                    'nameStyle' => (string) ($updated['nameStyle'] ?? 'default'),
                    'profileTitle' => (string) ($updated['profileTitle'] ?? ''),
                    'profileMotto' => (string) ($updated['profileMotto'] ?? ''),
                    'favoriteDrink' => (string) ($updated['favoriteDrink'] ?? ''),
                    'bannerUrl' => (string) ($updated['bannerUrl'] ?? ''),
                ]
            ]);
        }
        app_flash('success', 'Profil mis a jour.');
        app_redirect(['page' => 'dashboard']);
    }

    if ($action === 'toggle_admin_mode') {
        if (($currentUser['id'] ?? '') !== 'u5') {
            if (app_is_ajax_request()) {
                app_json_response(false, 'Action reservee au compte Guilhem.');
            }
            app_flash('error', 'Action reservee au compte Guilhem.');
            app_redirect(['page' => 'dashboard']);
        }

        $enabled = (string) ($_POST['admin_mode'] ?? '0') === '1';
        $_SESSION['admin_mode_enabled'] = $enabled;
        if (app_is_ajax_request()) {
            app_json_response(true, $enabled ? 'Mode admin active.' : 'Mode admin desactive.', [
                'adminEnabled' => $enabled,
            ]);
        }
        app_flash('success', $enabled ? 'Mode admin active.' : 'Mode admin desactive.');
        app_redirect(['page' => 'dashboard']);
    }

    if ($action === 'poll_notifications') {
        $unread = app_collect_and_mark_notifications($data, (string) $currentUser['id'], 15);
        app_json_response(true, 'Notifications synchronisees.', ['notifications' => $unread]);
    }

    if ($action === 'create_post') {
        $description = trim((string) ($_POST['description'] ?? ''));
        $partyId = trim((string) ($_POST['party_id'] ?? ''));

        if ($description === '' || $partyId === '') {
            if (app_is_ajax_request()) {
                app_json_response(false, 'Description et soiree obligatoires.');
            }
            app_flash('error', 'Description et soiree obligatoires.');
            app_redirect(['page' => 'feed']);
        }

        $partyIdx = app_find_party_index($data, $partyId);
        if ($partyIdx < 0) {
            if (app_is_ajax_request()) {
                app_json_response(false, 'Soiree introuvable.');
            }
            app_flash('error', 'Soiree introuvable.');
            app_redirect(['page' => 'feed']);
        }

        $mediaFingerprint = '';
        if (isset($_FILES['post_media_files']['name'])) {
            $rawNames = $_FILES['post_media_files']['name'];
            if (is_array($rawNames)) {
                $names = [];
                foreach ($rawNames as $n) {
                    if (is_string($n) && $n !== '') {
                        $names[] = $n;
                    }
                }
                sort($names);
                $mediaFingerprint = implode('|', $names);
            } elseif (is_string($rawNames)) {
                $mediaFingerprint = $rawNames;
            }
        }
        $guardKey = 'post_' . md5((string) $currentUser['id'] . '|' . $partyId . '|' . $description . '|' . $mediaFingerprint);
        if (!app_submission_guard($guardKey, 4)) {
            if (app_is_ajax_request()) {
                app_json_response(false, 'Post deja envoye. Attends 2 secondes.');
            }
            app_flash('info', 'Post deja envoye. Attends 2 secondes.');
            app_redirect(['page' => 'feed']);
        }

        $uploadResult = app_store_uploaded_media_batch('post_media_files', 8);
        $media = is_array($uploadResult['media'] ?? null) ? $uploadResult['media'] : [];
        $selectedMediaCount = (int) ($uploadResult['selected'] ?? 0);
        if ($selectedMediaCount > 0 && $media === []) {
            $errorMessage = 'Upload impossible: medias non supportes.';
            if (((int) ($uploadResult['tooLarge'] ?? 0)) > 0) {
                $maxUpload = ini_get('upload_max_filesize');
                $errorMessage = 'Fichier trop lourd pour le serveur (max ' . ($maxUpload !== false ? $maxUpload : '?') . ').';
            } elseif (((int) ($uploadResult['failed'] ?? 0)) > 0) {
                $errorMessage = 'Echec upload media. Reessaie avec un fichier plus leger.';
            }
            if (app_is_ajax_request()) {
                app_json_response(false, $errorMessage);
            }
            app_flash('error', $errorMessage);
            app_redirect(['page' => 'feed']);
        }

        $score = app_compute_post_points($description, $media);
        $post = app_normalize_post([
            'id' => app_generate_id('post'),
            'userId' => $currentUser['id'],
            'partyId' => $partyId,
            'media' => $media,
            'description' => $description,
            'pointsAwarded' => (int) ($score['points'] ?? 0),
            'gmComment' => (string) ($score['comment'] ?? 'Post publie.'),
            'timestamp' => time(),
            'likes' => [],
        ]);
        array_unshift($data['posts'], $post);
        $data['users'][$currentUserIndex]['points'] = max(
            0,
            (int) ($data['users'][$currentUserIndex]['points'] ?? 0) + (int) ($post['pointsAwarded'] ?? 0)
        );
        foreach ($data['users'] as $member) {
            if (($member['role'] ?? '') !== ROLE_MEMBER) {
                continue;
            }
            $toId = (string) ($member['id'] ?? '');
            if ($toId === '' || $toId === (string) $currentUser['id']) {
                continue;
            }
            app_push_notification(
                $data,
                $toId,
                'post',
                'Nouveau post',
                (string) $currentUser['name'] . ' a poste dans ' . (string) ($data['parties'][$partyIdx]['name'] ?? 'une soiree')
            );
        }

        app_save_data($data);
        if (app_is_ajax_request()) {
            app_json_response(true, 'Post cree. +' . (int) ($post['pointsAwarded'] ?? 0) . ' pts.', [
                'post' => $post,
                'owner' => [
                    'id' => (string) $currentUser['id'],
                    'name' => (string) $currentUser['name'],
                    'avatarUrl' => (string) $currentUser['avatarUrl'],
                ],
                'party' => $data['parties'][$partyIdx],
                'ownerPoints' => (int) ($data['users'][$currentUserIndex]['points'] ?? 0),
            ]);
        }
        app_flash('success', 'Post cree.');
        app_redirect(['page' => 'feed']);
    }

    if ($action === 'toggle_like') {
        $postId = trim((string) ($_POST['post_id'] ?? ''));
        $redirectPage = trim((string) ($_POST['redirect_page'] ?? 'feed'));

        $postIdx = app_find_post_index($data, $postId);
        if ($postIdx >= 0) {
            $likes = $data['posts'][$postIdx]['likes'] ?? [];
            if (!is_array($likes)) {
                $likes = [];
            }

            $uid = (string) $currentUser['id'];
            $postOwnerId = (string) ($data['posts'][$postIdx]['userId'] ?? '');
            if ($postOwnerId === $uid) {
                if (app_is_ajax_request()) {
                    app_json_response(false, 'Tu ne peux pas liker ton propre post.');
                }
                app_flash('info', 'Tu ne peux pas liker ton propre post.');
                app_redirect(['page' => app_safe_page($redirectPage, app_can_access_admin($currentUser))]);
            }

            $liked = in_array($uid, $likes, true);
            $likeDelta = 0;
            if ($liked) {
                $likes = array_values(array_filter($likes, static fn(string $id): bool => $id !== $uid));
                $likeDelta = -5;
            } else {
                $likes[] = $uid;
                $likes = array_values(array_unique($likes));
                $likeDelta = 5;
            }
            $data['posts'][$postIdx]['likes'] = $likes;

            $ownerIdx = app_find_user_index($data, $postOwnerId);
            $ownerPoints = 0;
            if ($ownerIdx >= 0) {
                $ownerPoints = max(0, (int) ($data['users'][$ownerIdx]['points'] ?? 0) + $likeDelta);
                $data['users'][$ownerIdx]['points'] = $ownerPoints;
            }

            app_save_data($data);
            if (app_is_ajax_request()) {
                app_json_response(true, 'Like mis a jour.', [
                    'likesCount' => count($likes),
                    'liked' => !$liked,
                    'postId' => $postId,
                    'ownerId' => $postOwnerId,
                    'ownerPoints' => $ownerPoints,
                ]);
            }
        }
        if ($postIdx < 0 && app_is_ajax_request()) {
            app_json_response(false, 'Post introuvable.');
        }

        app_redirect(['page' => app_safe_page($redirectPage, app_can_access_admin($currentUser))]);
    }

    if ($action === 'delete_post') {
        $postId = trim((string) ($_POST['post_id'] ?? ''));
        $redirectPage = trim((string) ($_POST['redirect_page'] ?? 'feed'));

        $postIdx = app_find_post_index($data, $postId);
        if ($postIdx < 0) {
            if (app_is_ajax_request()) {
                app_json_response(false, 'Post introuvable.');
            }
            app_flash('error', 'Post introuvable.');
            app_redirect(['page' => app_safe_page($redirectPage, app_can_access_admin($currentUser))]);
        }

        $post = $data['posts'][$postIdx];
        $postOwnerId = (string) ($post['userId'] ?? '');
        $isOwner = $postOwnerId === (string) $currentUser['id'];
        if (!$isOwner && !app_can_access_admin($currentUser)) {
            if (app_is_ajax_request()) {
                app_json_response(false, 'Suppression non autorisee.');
            }
            app_flash('error', 'Suppression non autorisee.');
            app_redirect(['page' => app_safe_page($redirectPage, app_can_access_admin($currentUser))]);
        }

        $postPoints = (int) ($post['pointsAwarded'] ?? 0);
        $likes = is_array($post['likes'] ?? null) ? array_values(array_unique($post['likes'])) : [];
        $likePoints = count($likes) * 5;
        $ownerIdx = app_find_user_index($data, $postOwnerId);
        $ownerPoints = 0;
        if ($ownerIdx >= 0) {
            $ownerPoints = max(0, (int) ($data['users'][$ownerIdx]['points'] ?? 0) - $postPoints - $likePoints);
            $data['users'][$ownerIdx]['points'] = $ownerPoints;
        }

        array_splice($data['posts'], $postIdx, 1);
        app_save_data($data);

        if (app_is_ajax_request()) {
            app_json_response(true, 'Post supprime.', [
                'postId' => $postId,
                'ownerId' => $postOwnerId,
                'ownerPoints' => $ownerPoints,
            ]);
        }
        app_flash('success', 'Post supprime.');
        app_redirect(['page' => app_safe_page($redirectPage, app_can_access_admin($currentUser))]);
    }

    if ($action === 'create_party') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $location = trim((string) ($_POST['location_name'] ?? ''));
        $date = trim((string) ($_POST['date'] ?? date('Y-m-d')));
        $latRaw = trim((string) ($_POST['lat'] ?? ''));
        $lngRaw = trim((string) ($_POST['lng'] ?? ''));

        if ($name === '' || $location === '') {
            if (app_is_ajax_request()) {
                app_json_response(false, 'Nom et lieu obligatoires.');
            }
            app_flash('error', 'Nom et lieu obligatoires.');
            app_redirect(['page' => 'parties']);
        }

        $lat = is_numeric($latRaw) ? (float) $latRaw : null;
        $lng = is_numeric($lngRaw) ? (float) $lngRaw : null;
        $cover = app_store_uploaded_image('party_cover_file') ?? 'logo.png';

        $guardKey = 'party_' . md5((string) $currentUser['id'] . '|' . $name . '|' . $location . '|' . $date);
        if (!app_submission_guard($guardKey, 4)) {
            if (app_is_ajax_request()) {
                app_json_response(false, 'Soiree deja envoyee. Attends 2 secondes.');
            }
            app_flash('info', 'Soiree deja envoyee. Attends 2 secondes.');
            app_redirect(['page' => 'parties']);
        }

        $party = app_normalize_party([
            'id' => app_generate_id('party'),
            'name' => $name,
            'date' => $date !== '' ? $date : date('Y-m-d'),
            'locationName' => $location,
            'coverUrl' => $cover,
            'lat' => $lat,
            'lng' => $lng,
            'createdBy' => $currentUser['id'],
        ]);
        array_unshift($data['parties'], $party);

        app_save_data($data);
        if (app_is_ajax_request()) {
            app_json_response(true, 'Soiree ajoutee.', ['party' => $party]);
        }
        app_flash('success', 'Soiree ajoutee.');

        $redirectTo = trim((string) ($_POST['redirect_page'] ?? 'parties'));
        app_redirect(['page' => app_safe_page($redirectTo, app_can_access_admin($currentUser))]);
    }

    if ($action === 'admin_export_backup') {
        if (!app_can_access_admin($currentUser)) {
            app_flash('error', 'Acces admin requis.');
            app_redirect(['page' => 'dashboard']);
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="confrerie_backup_' . date('Y-m-d_H-i') . '.json"');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!app_can_access_admin($currentUser)) {
        app_flash('error', 'Action reservee admin.');
        app_redirect(['page' => 'dashboard']);
    }

    if ($action === 'admin_add_points') {
        $targetId = trim((string) ($_POST['target_user_id'] ?? ''));
        $delta = (int) ($_POST['delta_points'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));

        $targetIdx = app_find_user_index($data, $targetId);
        if ($targetIdx < 0 || (($data['users'][$targetIdx]['role'] ?? '') === ROLE_ADMIN)) {
            app_flash('error', 'Joueur invalide.');
            app_redirect(['page' => 'admin']);
        }

        $currentPoints = (int) ($data['users'][$targetIdx]['points'] ?? 0);
        $newPoints = max(0, $currentPoints + $delta);
        $data['users'][$targetIdx]['points'] = $newPoints;

        $data['activity'][] = [
            'id' => app_generate_id('act'),
            'type' => 'points',
            'by' => (string) $currentUser['id'],
            'target' => (string) $targetId,
            'delta' => $delta,
            'reason' => app_cut($reason, 120),
            'timestamp' => time(),
        ];

        app_save_data($data);
        if (app_is_ajax_request()) {
            app_json_response(true, 'Points mis a jour.');
        }
        app_flash('success', 'Points mis a jour.');
        app_redirect(['page' => 'admin']);
    }

    if ($action === 'admin_create_user') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $className = trim((string) ($_POST['class_name'] ?? 'Fetard'));
        $role = strtoupper(trim((string) ($_POST['role'] ?? ROLE_MEMBER)));
        $password = trim((string) ($_POST['password'] ?? ''));
        $avatarUrlInput = trim((string) ($_POST['avatar_url'] ?? ''));

        if ($name === '') {
            app_flash('error', 'Nom obligatoire.');
            app_redirect(['page' => 'admin']);
        }

        if ($role !== ROLE_ADMIN) {
            $role = ROLE_MEMBER;
        }

        if ($role === ROLE_ADMIN && strlen($password) < 4) {
            app_flash('error', 'Mot de passe admin obligatoire (min 4).');
            app_redirect(['page' => 'admin']);
        }

        $avatar = app_store_uploaded_image('new_user_avatar_file');
        if ($avatar === null) {
            $avatar = ($avatarUrlInput !== '' && filter_var($avatarUrlInput, FILTER_VALIDATE_URL)) ? $avatarUrlInput : app_seed_avatar($name);
        }

        $newUser = app_normalize_user([
            'id' => app_generate_id('user'),
            'name' => app_cut($name, 40),
            'role' => $role,
            'points' => 0,
            'className' => app_cut($className !== '' ? $className : 'Fetard', 40),
            'bio' => '',
            'avatarUrl' => $avatar,
            'inventory' => [],
            'achievements' => [],
            'password_hash' => $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null,
            'must_set_password' => $role === ROLE_MEMBER,
            'theme' => 'neon',
        ]);

        if ($role === ROLE_MEMBER && $password !== '') {
            $newUser['must_set_password'] = false;
        }

        $data['users'][] = $newUser;
        app_save_data($data);
        if (app_is_ajax_request()) {
            app_json_response(true, 'Compte cree.');
        }
        app_flash('success', 'Compte cree.');
        app_redirect(['page' => 'admin']);
    }

    if ($action === 'admin_delete_user') {
        $targetId = trim((string) ($_POST['target_user_id'] ?? ''));
        $targetIdx = app_find_user_index($data, $targetId);

        if ($targetIdx < 0) {
            app_flash('error', 'Utilisateur introuvable.');
            app_redirect(['page' => 'admin']);
        }

        $target = $data['users'][$targetIdx];
        if (($target['role'] ?? '') === ROLE_ADMIN || ($target['id'] ?? '') === ($currentUser['id'] ?? '')) {
            app_flash('error', 'Suppression impossible pour ce compte.');
            app_redirect(['page' => 'admin']);
        }

        array_splice($data['users'], $targetIdx, 1);

        foreach ($data['posts'] as $postIndex => $post) {
            $likes = $post['likes'] ?? [];
            if (is_array($likes)) {
                $data['posts'][$postIndex]['likes'] = array_values(array_filter(
                    $likes,
                    static fn(string $uid): bool => $uid !== $targetId
                ));
            }
        }

        app_save_data($data);
        if (app_is_ajax_request()) {
            app_json_response(true, 'Compte supprime.');
        }
        app_flash('success', 'Compte supprime.');
        app_redirect(['page' => 'admin']);
    }

    if ($action === 'admin_toggle_map') {
        $enabled = (string) ($_POST['is_map_enabled'] ?? '1');
        $data['settings']['isMapEnabled'] = $enabled === '1';
        app_save_data($data);
        if (app_is_ajax_request()) {
            app_json_response(true, 'Parametre carte mis a jour.', ['isMapEnabled' => $data['settings']['isMapEnabled']]);
        }
        app_flash('success', 'Parametre carte mis a jour.');
        app_redirect(['page' => 'admin']);
    }

    if ($action === 'admin_create_challenge') {
        $text = trim((string) ($_POST['challenge_text'] ?? ''));
        $difficulty = trim((string) ($_POST['challenge_difficulty'] ?? 'Moyen'));

        if ($text === '') {
            app_flash('error', 'Texte du defi obligatoire.');
            app_redirect(['page' => 'admin']);
        }
        if (!in_array($difficulty, ['Facile', 'Moyen', 'Hardcore'], true)) {
            $difficulty = 'Moyen';
        }

        $data['challenges'][] = app_normalize_challenge([
            'id' => app_generate_id('challenge'),
            'text' => app_cut($text, 180),
            'difficulty' => $difficulty,
        ]);
        app_save_data($data);
        if (app_is_ajax_request()) {
            app_json_response(true, 'Defi ajoute.');
        }
        app_flash('success', 'Defi ajoute.');
        app_redirect(['page' => 'admin']);
    }

    if ($action === 'admin_delete_challenge') {
        $challengeId = trim((string) ($_POST['challenge_id'] ?? ''));
        $newChallenges = [];
        foreach ($data['challenges'] as $challenge) {
            if (($challenge['id'] ?? '') !== $challengeId) {
                $newChallenges[] = $challenge;
            }
        }
        $data['challenges'] = $newChallenges;
        app_save_data($data);
        if (app_is_ajax_request()) {
            app_json_response(true, 'Defi supprime.');
        }
        app_flash('success', 'Defi supprime.');
        app_redirect(['page' => 'admin']);
    }

    if ($action === 'admin_update_party_cover') {
        $partyId = trim((string) ($_POST['party_id'] ?? ''));
        $partyIdx = app_find_party_index($data, $partyId);
        if ($partyIdx < 0) {
            if (app_is_ajax_request()) {
                app_json_response(false, 'Soiree introuvable.');
            }
            app_flash('error', 'Soiree introuvable.');
            app_redirect(['page' => 'admin']);
        }

        $cover = app_store_uploaded_image('party_cover_file');
        $coverUrlInput = trim((string) ($_POST['party_cover_url'] ?? ''));
        if ($cover === null && $coverUrlInput !== '' && filter_var($coverUrlInput, FILTER_VALIDATE_URL)) {
            $cover = $coverUrlInput;
        }
        if ($cover === null || $cover === '') {
            if (app_is_ajax_request()) {
                app_json_response(false, 'Image de couverture requise.');
            }
            app_flash('error', 'Image de couverture requise.');
            app_redirect(['page' => 'admin']);
        }

        $data['parties'][$partyIdx]['coverUrl'] = $cover;
        app_save_data($data);
        if (app_is_ajax_request()) {
            app_json_response(true, 'Photo de soiree mise a jour.', [
                'partyId' => $partyId,
                'coverUrl' => $cover,
            ]);
        }
        app_flash('success', 'Photo de soiree mise a jour.');
        app_redirect(['page' => 'admin']);
    }

    if ($action === 'admin_delete_party') {
        $partyId = trim((string) ($_POST['party_id'] ?? ''));
        $partyIdx = app_find_party_index($data, $partyId);
        if ($partyIdx < 0) {
            if (app_is_ajax_request()) {
                app_json_response(false, 'Soiree introuvable.');
            }
            app_flash('error', 'Soiree introuvable.');
            app_redirect(['page' => 'admin']);
        }

        $remainingPosts = [];
        foreach ($data['posts'] as $post) {
            if ((string) ($post['partyId'] ?? '') !== $partyId) {
                $remainingPosts[] = $post;
                continue;
            }

            $ownerId = (string) ($post['userId'] ?? '');
            $ownerIdx = app_find_user_index($data, $ownerId);
            if ($ownerIdx >= 0) {
                $likes = is_array($post['likes'] ?? null) ? $post['likes'] : [];
                $deduct = (int) ($post['pointsAwarded'] ?? 0) + (count($likes) * 5);
                $data['users'][$ownerIdx]['points'] = max(0, (int) ($data['users'][$ownerIdx]['points'] ?? 0) - $deduct);
            }
        }
        $data['posts'] = $remainingPosts;

        array_splice($data['parties'], $partyIdx, 1);
        app_save_data($data);
        if (app_is_ajax_request()) {
            app_json_response(true, 'Soiree supprimee.', ['partyId' => $partyId]);
        }
        app_flash('success', 'Soiree supprimee.');
        app_redirect(['page' => 'admin']);
    }

    if ($action === 'admin_create_item') {
        $name = trim((string) ($_POST['item_name'] ?? ''));
        $description = trim((string) ($_POST['item_desc'] ?? ''));
        $rarity = trim((string) ($_POST['item_rarity'] ?? 'Commune'));
        $target = trim((string) ($_POST['item_target'] ?? ''));

        if ($name === '' || $target === '') {
            app_flash('error', 'Nom objet + destinataire requis.');
            app_redirect(['page' => 'admin']);
        }

        $imageUrl = app_store_uploaded_image('item_image_file') ?? '';
        $item = app_normalize_item([
            'id' => app_generate_id('item'),
            'name' => app_cut($name, 40),
            'description' => app_cut($description !== '' ? $description : 'Objet mysterieux', 120),
            'rarity' => $rarity,
            'imageUrl' => $imageUrl,
            'stats' => 'Special',
        ]);

        if ($target === 'ALL') {
            foreach ($data['users'] as $idx => $user) {
                if (($user['role'] ?? '') === ROLE_MEMBER) {
                    $copy = $item;
                    $copy['id'] = app_generate_id('item');
                    $data['users'][$idx]['inventory'][] = $copy;
                    app_push_notification(
                        $data,
                        (string) ($data['users'][$idx]['id'] ?? ''),
                        'item',
                        'Nouvel objet recu',
                        'Tu as recu: ' . (string) ($item['name'] ?? 'Objet')
                    );
                }
            }
        } else {
            $targetIdx = app_find_user_index($data, $target);
            if ($targetIdx < 0) {
                app_flash('error', 'Destinataire invalide.');
                app_redirect(['page' => 'admin']);
            }
            $data['users'][$targetIdx]['inventory'][] = $item;
            app_push_notification(
                $data,
                (string) ($data['users'][$targetIdx]['id'] ?? ''),
                'item',
                'Nouvel objet recu',
                'Tu as recu: ' . (string) ($item['name'] ?? 'Objet')
            );
        }

        app_save_data($data);
        if (app_is_ajax_request()) {
            app_json_response(true, 'Objet distribue.');
        }
        app_flash('success', 'Objet distribue.');
        app_redirect(['page' => 'admin']);
    }

    if ($action === 'admin_create_achievement') {
        $name = trim((string) ($_POST['ach_name'] ?? ''));
        $description = trim((string) ($_POST['ach_desc'] ?? ''));
        $icon = trim((string) ($_POST['ach_icon'] ?? '🏆'));
        if ($name === '') {
            app_flash('error', 'Nom succes requis.');
            app_redirect(['page' => 'admin']);
        }

        $achievement = app_normalize_achievement([
            'id' => app_generate_id('ach'),
            'name' => app_cut($name, 50),
            'description' => app_cut($description !== '' ? $description : 'Succes debloque', 120),
            'icon' => app_cut($icon, 4),
            'unlockedAt' => time(),
        ]);
        $data['achievementLibrary'][] = $achievement;
        app_save_data($data);
        if (app_is_ajax_request()) {
            app_json_response(true, 'Succes ajoute a la bibliotheque.', ['achievement' => $achievement]);
        }
        app_flash('success', 'Succes ajoute a la bibliotheque.');
        app_redirect(['page' => 'admin']);
    }

    if ($action === 'admin_assign_achievement') {
        $achievementId = trim((string) ($_POST['achievement_id'] ?? ''));
        $target = trim((string) ($_POST['ach_target'] ?? ''));

        if ($achievementId === '' || $target === '') {
            app_flash('error', 'Succes et destinataire requis.');
            app_redirect(['page' => 'admin']);
        }

        $achievement = null;
        foreach ($data['achievementLibrary'] as $entry) {
            if (($entry['id'] ?? '') === $achievementId) {
                $achievement = app_normalize_achievement($entry);
                break;
            }
        }
        if ($achievement === null) {
            app_flash('error', 'Succes introuvable.');
            app_redirect(['page' => 'admin']);
        }

        if ($target === 'ALL') {
            foreach ($data['users'] as $idx => $user) {
                if (($user['role'] ?? '') === ROLE_MEMBER) {
                    $already = false;
                    foreach ($data['users'][$idx]['achievements'] as $existing) {
                        if (($existing['name'] ?? '') === $achievement['name']) {
                            $already = true;
                            break;
                        }
                    }
                    if (!$already) {
                        $copy = $achievement;
                        $copy['id'] = app_generate_id('ach');
                        $data['users'][$idx]['achievements'][] = $copy;
                        app_push_notification(
                            $data,
                            (string) ($data['users'][$idx]['id'] ?? ''),
                            'achievement',
                            'Succes debloque',
                            'Tu as obtenu: ' . (string) ($achievement['name'] ?? 'Succes')
                        );
                    }
                }
            }
        } else {
            $targetIdx = app_find_user_index($data, $target);
            if ($targetIdx < 0) {
                app_flash('error', 'Destinataire invalide.');
                app_redirect(['page' => 'admin']);
            }
            $data['users'][$targetIdx]['achievements'][] = $achievement;
            app_push_notification(
                $data,
                (string) ($data['users'][$targetIdx]['id'] ?? ''),
                'achievement',
                'Succes debloque',
                'Tu as obtenu: ' . (string) ($achievement['name'] ?? 'Succes')
            );
        }

        app_save_data($data);
        if (app_is_ajax_request()) {
            app_json_response(true, 'Succes distribue.');
        }
        app_flash('success', 'Succes distribue.');
        app_redirect(['page' => 'admin']);
    }

    if ($action === 'admin_import_backup') {
        if (!isset($_FILES['backup_file']) || !is_array($_FILES['backup_file'])) {
            app_flash('error', 'Fichier backup manquant.');
            app_redirect(['page' => 'admin']);
        }

        $file = $_FILES['backup_file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            app_flash('error', 'Import impossible.');
            app_redirect(['page' => 'admin']);
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $raw = $tmpName !== '' ? file_get_contents($tmpName) : false;
        if ($raw === false) {
            app_flash('error', 'Lecture du backup impossible.');
            app_redirect(['page' => 'admin']);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            app_flash('error', 'JSON invalide.');
            app_redirect(['page' => 'admin']);
        }

        $imported = app_normalize_data($decoded);

        $hasAdmin = false;
        foreach ($imported['users'] as $user) {
            if (($user['role'] ?? '') === ROLE_ADMIN) {
                $hasAdmin = true;
                break;
            }
        }

        if (!$hasAdmin) {
            $defaults = app_default_data();
            $imported['users'][] = $defaults['users'][0];
        }

        app_save_data($imported);
        app_flash('success', 'Backup restaure.');
        app_redirect(['page' => 'admin']);
    }

    app_flash('error', 'Action inconnue.');
    app_redirect(['page' => 'dashboard']);
}

$data = app_load_data();
$currentUser = app_current_user($data);
$isLoggedIn = $currentUser !== null;
if ($isLoggedIn && (($currentUser['id'] ?? '') !== 'u5') && (($currentUser['role'] ?? '') !== ROLE_ADMIN)) {
    $_SESSION['admin_mode_enabled'] = false;
}
$isAdmin = app_can_access_admin($currentUser);

if ($isLoggedIn && isset($_GET['download']) && $_GET['download'] === 'backup' && $isAdmin) {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="confrerie_backup_' . date('Y-m-d_H-i') . '.json"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$page = app_safe_page((string) ($_GET['page'] ?? 'dashboard'), $isAdmin);
$flash = app_flash();

$users = $data['users'];
$userById = app_user_name_map($users);
$parties = $data['parties'];
$posts = app_sort_posts($data['posts']);

$memberUsers = array_values(array_filter($users, static fn(array $user): bool => ($user['role'] ?? '') === ROLE_MEMBER));
$rankedUsers = $memberUsers;
usort($rankedUsers, static fn(array $a, array $b): int => ((int) $b['points']) <=> ((int) $a['points']));

$viewingUser = $currentUser;
if ($isLoggedIn && $page === 'dashboard') {
    $viewUserId = trim((string) ($_GET['view_user'] ?? ''));
    if ($viewUserId !== '') {
        $viewIdx = app_find_user_index($data, $viewUserId);
        if ($viewIdx >= 0) {
            $viewingUser = $data['users'][$viewIdx];
        }
    }
}
$isOwnProfile = $isLoggedIn && $viewingUser !== null && (($viewingUser['id'] ?? '') === ($currentUser['id'] ?? ''));
$viewUserSafe = is_array($viewingUser) ? $viewingUser : [];
$viewProfileThemeClass = app_get_profile_theme_class($viewUserSafe);
$viewNameStyleClass = app_get_name_style_class($viewUserSafe);
$viewProfileTitle = (string) (($viewUserSafe['profileTitle'] ?? '') ?: ($viewUserSafe['className'] ?? ''));
$viewProfileMotto = (string) ($viewUserSafe['profileMotto'] ?? '');
$viewFavoriteDrink = (string) ($viewUserSafe['favoriteDrink'] ?? '');
$viewBannerUrl = (string) ($viewUserSafe['bannerUrl'] ?? '');

$selectedParty = null;
if ($page === 'parties' && isset($_GET['party'])) {
    $partyId = trim((string) $_GET['party']);
    $partyIdx = app_find_party_index($data, $partyId);
    if ($partyIdx >= 0) {
        $selectedParty = $data['parties'][$partyIdx];
    }
}

$mustSetPassword = $isLoggedIn && (bool) ($currentUser['must_set_password'] ?? false);
$canUnlockAdmin = $isLoggedIn && (($currentUser['id'] ?? '') === 'u5');
$adminModeEnabled = !empty($_SESSION['admin_mode_enabled']);
$themeClass = app_get_theme_class($currentUser);
$randomChallenge = app_random_challenge($data['challenges']);
$pendingNotifications = [];
if ($isLoggedIn && $currentUser !== null) {
    $pendingNotifications = app_collect_and_mark_notifications($data, (string) $currentUser['id'], 30);
}

$postsByParty = [];
foreach ($posts as $post) {
    $postsByParty[$post['partyId']][] = $post;
}

$allUniqueItems = [];
$allUniqueAchievements = [];
if ($isAdmin) {
    foreach ($memberUsers as $member) {
        foreach ($member['inventory'] as $item) {
            $allUniqueItems[$item['name']] = $item;
        }
        foreach ($member['achievements'] as $achievement) {
            $allUniqueAchievements[$achievement['name']] = $achievement;
        }
    }
    foreach (($data['achievementLibrary'] ?? []) as $achievement) {
        if (is_array($achievement) && isset($achievement['name'])) {
            $allUniqueAchievements[$achievement['name']] = $achievement;
        }
    }
}

?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0" />
  <meta name="theme-color" content="#0f0e24" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
  <meta name="apple-mobile-web-app-title" content="La Confrerie" />
  <link rel="manifest" href="manifest.webmanifest" />
  <link rel="icon" href="logo.png" />
  <link rel="apple-touch-icon" href="logo.png" />
  <title>La Confrerie</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            neon: {
              pink: '#ec4899',
              purple: '#a855f7',
              blue: '#3b82f6',
              dark: '#0f0e24',
              surface: '#1e1b4b',
            }
          },
          fontFamily: {
            sans: ['Inter', 'sans-serif'],
            display: ['Oswald', 'sans-serif'],
          },
          animation: {
            'pulse-fast': 'pulse 1.5s cubic-bezier(0.4,0,0.6,1) infinite',
          }
        }
      }
    }
  </script>

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Oswald:wght@500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="" />
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>

  <style>
    :root {
      --bg-main: #0f0e24;
      --surface: #1e1b4b;
      --accent-a: #3b82f6;
      --accent-b: #ec4899;
    }

    body.theme-gold {
      --bg-main: #18130a;
      --surface: #2b2111;
      --accent-a: #f59e0b;
      --accent-b: #facc15;
    }

    body.theme-ocean {
      --bg-main: #08141d;
      --surface: #112635;
      --accent-a: #06b6d4;
      --accent-b: #22d3ee;
    }

    body.theme-crimson {
      --bg-main: #1f0d16;
      --surface: #3b1226;
      --accent-a: #f43f5e;
      --accent-b: #fb7185;
    }

    body.theme-frost {
      --bg-main: #0a1624;
      --surface: #10293f;
      --accent-a: #38bdf8;
      --accent-b: #a5f3fc;
    }

    body {
      background-color: var(--bg-main);
      color: #e0e7ff;
      -webkit-tap-highlight-color: transparent;
      font-family: 'Inter', sans-serif;
      margin: 0;
      min-height: 100vh;
      touch-action: manipulation;
      overflow-x: hidden;
      scrollbar-width: none;
      scroll-behavior: smooth;
    }

    html {
      scrollbar-width: none;
    }

    html::-webkit-scrollbar,
    body::-webkit-scrollbar,
    *::-webkit-scrollbar {
      width: 0 !important;
      height: 0 !important;
    }

    .no-scrollbar::-webkit-scrollbar {
      display: none;
    }

    .no-scrollbar {
      -ms-overflow-style: none;
      scrollbar-width: none;
    }

    .app-shell {
      background: var(--bg-main);
      isolation: isolate;
    }

    .modal-hidden {
      display: none !important;
    }

    .flash-success { background: rgba(34,197,94,0.2); border-color: rgba(34,197,94,0.5); color: #86efac; }
    .flash-error { background: rgba(239,68,68,0.2); border-color: rgba(239,68,68,0.5); color: #fca5a5; }
    .flash-info { background: rgba(59,130,246,0.2); border-color: rgba(59,130,246,0.5); color: #93c5fd; }

    #leafletMap {
      width: 100%;
      height: clamp(300px, calc(100svh - 330px), 56vh);
      min-height: 300px;
      max-height: 60vh;
      border-radius: 24px;
      overflow: hidden;
      border: 1px solid rgba(255,255,255,0.12);
      box-shadow: 0 20px 50px rgba(0,0,0,0.45);
      position: relative;
      z-index: 5;
    }

    .leaflet-container {
      background: #111827;
    }

    .leaflet-top,
    .leaflet-bottom,
    .leaflet-pane,
    .leaflet-control {
      z-index: 10;
    }

    #mapPartyOverlay,
    #mapPartyModal {
      position: fixed !important;
      inset: 0;
      pointer-events: auto;
      overscroll-behavior: contain;
      -webkit-overflow-scrolling: touch;
    }

    body.map-layer-locked {
      overflow: hidden;
    }

    body.map-layer-locked #leafletMap {
      pointer-events: none !important;
    }

    .custom-party-marker {
      border-radius: 9999px;
      border: 3px solid #ec4899;
      box-shadow: 0 0 12px rgba(236,72,153,0.6);
    }

    .safe-bottom {
      padding-bottom: max(env(safe-area-inset-bottom), 1rem);
    }

    .bottom-safe-nav {
      bottom: calc(env(safe-area-inset-bottom, 0px) + 8px);
    }

    .fade-in-up {
      animation: fadeInUp 300ms cubic-bezier(0.22, 1, 0.36, 1) both;
    }

    .card-lift {
      transition: transform 220ms cubic-bezier(0.22, 1, 0.36, 1), box-shadow 260ms ease, border-color 260ms ease;
      will-change: transform;
    }

    .card-lift:hover {
      transform: translateY(-2px);
      box-shadow: 0 20px 45px rgba(0, 0, 0, 0.28);
    }

    .card-lift:active {
      transform: scale(0.985) translateY(0);
    }

    @keyframes fadeInUp {
      from {
        opacity: 0;
        transform: translateY(8px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .tap-button {
      transition: transform 160ms cubic-bezier(0.22, 1, 0.36, 1), opacity 140ms ease, box-shadow 190ms ease, filter 180ms ease;
      will-change: transform;
    }

    .tap-button:hover {
      filter: brightness(1.08);
    }

    .tap-button:active {
      transform: scale(0.97);
    }

    .profile-theme-midnight {
      background: linear-gradient(135deg, rgba(24, 28, 52, 0.92), rgba(12, 14, 28, 0.96));
      border-color: rgba(129, 140, 248, 0.3);
    }

    .profile-theme-sunset {
      background: linear-gradient(135deg, rgba(82, 24, 38, 0.9), rgba(42, 16, 64, 0.92), rgba(18, 15, 40, 0.96));
      border-color: rgba(251, 146, 60, 0.35);
    }

    .profile-theme-emerald {
      background: linear-gradient(135deg, rgba(10, 66, 68, 0.9), rgba(17, 60, 90, 0.92), rgba(12, 16, 30, 0.95));
      border-color: rgba(16, 185, 129, 0.34);
    }

    .profile-theme-aurora {
      background: linear-gradient(135deg, rgba(28, 20, 84, 0.9), rgba(20, 76, 105, 0.9), rgba(12, 19, 45, 0.95));
      border-color: rgba(125, 211, 252, 0.4);
    }

    .profile-theme-obsidian {
      background: linear-gradient(135deg, rgba(17, 24, 39, 0.96), rgba(9, 9, 11, 0.98));
      border-color: rgba(156, 163, 175, 0.34);
    }

    .name-style-default {
      color: #fff;
    }

    .name-style-sunfire,
    .name-style-aqua,
    .name-style-royal,
    .name-style-rainbow {
      color: transparent;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      -webkit-background-clip: text;
      background-size: 220% 220%;
      animation: nameGradientShift 3.4s linear infinite;
    }

    .name-style-sunfire {
      background-image: linear-gradient(90deg, #fde047, #fb7185, #f97316, #fde047);
    }

    .name-style-aqua {
      background-image: linear-gradient(90deg, #22d3ee, #06b6d4, #67e8f9, #22d3ee);
    }

    .name-style-royal {
      background-image: linear-gradient(90deg, #a78bfa, #60a5fa, #c4b5fd, #a78bfa);
    }

    .name-style-rainbow {
      background-image: linear-gradient(90deg, #fb7185, #f59e0b, #eab308, #34d399, #60a5fa, #a78bfa, #fb7185);
    }

    @keyframes nameGradientShift {
      0% { background-position: 0% 50%; }
      100% { background-position: 100% 50%; }
    }

    .media-rail {
      scroll-snap-type: x mandatory;
      overscroll-behavior-x: contain;
      -webkit-overflow-scrolling: touch;
      scroll-behavior: smooth;
    }

    .media-rail > * {
      scroll-snap-align: center;
    }

    @media (prefers-reduced-motion: reduce) {
      .fade-in-up, .tap-button, .card-lift {
        animation: none !important;
        transition: none !important;
      }
    }
  </style>
</head>
<body class="<?= h($themeClass) ?>">
<?php if (!$isLoggedIn): ?>
  <div class="min-h-screen bg-[#0f0e24] flex flex-col items-center justify-center p-6 text-center">
    <?php if ($flash): ?>
      <div class="w-full max-w-sm mb-6 p-3 rounded-xl border flash-<?= h($flash['type']) ?>">
        <?= h((string) $flash['message']) ?>
      </div>
    <?php endif; ?>

    <div class="mb-10 relative">
      <div class="absolute inset-0 bg-neon-purple blur-3xl opacity-30"></div>
      <img src="logo.png" alt="Logo" class="relative z-10 w-20 h-20 mx-auto rounded-2xl shadow-2xl border border-white/20" />
      <h1 class="text-5xl font-display font-bold text-white mt-4 tracking-tighter uppercase drop-shadow-[0_0_10px_rgba(255,255,255,0.4)]">
        La <span class="text-neon-pink">Confrerie</span>
      </h1>
      <p class="text-neon-blue mt-2 font-bold tracking-widest uppercase text-sm">Drink • Share • Level Up</p>
    </div>

    <div class="w-full max-w-sm space-y-3">
      <?php foreach ($users as $user): ?>
        <button
          type="button"
          class="w-full flex items-center p-4 rounded-xl border transition-all duration-300 active:scale-95 group bg-neon-surface border-white/5 hover:border-neon-blue/50"
          data-open-login
          data-user-id="<?= h((string) $user['id']) ?>"
          data-user-name="<?= h((string) $user['name']) ?>"
          data-user-role="<?= h((string) $user['role']) ?>"
          data-user-has-password="<?= !empty($user['password_hash']) ? '1' : '0' ?>"
        >
          <img src="<?= h((string) $user['avatarUrl']) ?>" class="w-10 h-10 rounded-full mr-3 border border-gray-600 group-hover:border-white transition-colors object-cover" alt="" />
          <span class="text-white font-bold text-lg"><?= h((string) $user['name']) ?></span>
        </button>
      <?php endforeach; ?>
    </div>
  </div>

  <div id="loginModal" class="modal-hidden fixed inset-0 bg-black/85 z-50 flex items-center justify-center p-5">
    <div class="w-full max-w-sm bg-neon-surface border border-neon-blue/30 rounded-2xl p-5">
      <h2 class="text-white text-xl font-bold mb-2">Connexion</h2>
      <p id="loginModalHint" class="text-sm text-gray-400 mb-4"></p>

      <form method="post" class="space-y-4">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
        <input type="hidden" name="action" value="login" />
        <input type="hidden" id="loginUserId" name="user_id" value="" />

        <input
          id="loginPassword"
          type="password"
          name="password"
          class="w-full bg-black/40 border border-gray-700 rounded-lg p-3 text-white focus:border-neon-pink outline-none"
          placeholder="Mot de passe"
        />

        <div class="flex gap-2 pt-2">
          <button type="button" id="closeLoginModal" class="flex-1 py-3 text-gray-400 font-bold border border-gray-700 rounded-lg">Annuler</button>
          <button type="submit" class="flex-1 bg-gradient-to-r from-neon-blue to-neon-purple text-white font-bold py-3 rounded-xl">Entrer</button>
        </div>
      </form>
    </div>
  </div>
<?php else: ?>
  <div class="min-h-screen bg-[#0f0e24] text-white font-sans selection:bg-neon-pink selection:text-white safe-bottom">
    <main class="max-w-md mx-auto min-h-screen relative shadow-2xl overflow-x-hidden app-shell">
      <?php if ($flash): ?>
        <div class="px-4 pt-4">
          <div class="p-3 rounded-xl border flash-<?= h($flash['type']) ?> text-sm">
            <?= h((string) $flash['message']) ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($page === 'dashboard' && $viewingUser !== null): ?>
        <?php
          $rank = app_get_rank((int) $viewingUser['points']);
          $nextRank = app_get_next_rank((int) $viewingUser['points']);
          $progress = 100;
          if ($nextRank !== null) {
              $currentMin = (int) $rank['minPoints'];
              $nextMin = (int) $nextRank['minPoints'];
              $points = (int) $viewingUser['points'];
              $progress = $nextMin > $currentMin ? (($points - $currentMin) / ($nextMin - $currentMin)) * 100 : 100;
              $progress = max(0, min(100, $progress));
          }
        ?>
        <div class="p-4 space-y-6 pb-28 pt-6">
          <?php if ($mustSetPassword): ?>
            <div id="mustSetPasswordGate" class="fixed inset-0 z-50 bg-black/90 flex items-center justify-center p-4">
              <div class="bg-neon-surface w-full max-w-sm p-6 rounded-2xl border border-neon-pink/60">
                <h2 class="text-xl font-bold text-white mb-2">Securise ton compte</h2>
                <p class="text-sm text-gray-300 mb-4">Premiere connexion detectee. Definis ton mot de passe maintenant.</p>
                <form method="post" class="space-y-3 js-action-form" data-async="true" data-async-kind="set-password">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
                  <input type="hidden" name="action" value="set_password" />
                  <input type="password" name="new_password" minlength="4" required class="w-full bg-black/40 border border-gray-700 rounded-lg p-3 text-white" placeholder="Nouveau mot de passe" />
                  <button type="submit" class="w-full bg-neon-pink text-white font-bold py-3 rounded-xl">Enregistrer</button>
                </form>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($isOwnProfile): ?>
            <div id="settingsModal" class="modal-hidden fixed inset-0 z-50 bg-black/90 flex items-center justify-center p-4">
              <div class="bg-neon-surface w-full p-6 rounded-2xl border border-neon-purple max-h-[90vh] overflow-y-auto">
                <h2 class="text-xl font-bold text-white mb-4">Parametres</h2>
                <form method="post" enctype="multipart/form-data" class="space-y-4 js-action-form" data-async="true" data-async-kind="profile">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
                  <input type="hidden" name="action" value="update_profile" />

                  <div class="text-center">
                    <img id="profileAvatarPreview" src="<?= h((string) $currentUser['avatarUrl']) ?>" class="w-20 h-20 rounded-full mx-auto mb-2 object-cover border-2 border-neon-pink" alt="Avatar" />
                    <label class="text-xs text-neon-blue underline cursor-pointer">
                      Changer la photo
                      <input id="avatarFileInput" type="file" name="avatar_file" accept="image/*" class="hidden" />
                    </label>
                  </div>

                  <input name="name" value="<?= h((string) $currentUser['name']) ?>" class="w-full bg-black/50 border border-gray-600 rounded p-3 text-white" placeholder="Nom" />
                  <input name="class_name" value="<?= h((string) $currentUser['className']) ?>" class="w-full bg-black/50 border border-gray-600 rounded p-3 text-white" placeholder="Classe" />
                  <textarea name="bio" class="w-full bg-black/50 border border-gray-600 rounded p-3 text-white h-20 resize-none" placeholder="Bio"><?= h((string) $currentUser['bio']) ?></textarea>
                  <input name="profile_title" value="<?= h((string) ($currentUser['profileTitle'] ?? '')) ?>" class="w-full bg-black/50 border border-gray-600 rounded p-3 text-white" placeholder="Titre profil (ex: Roi du Zinc)" />
                  <input name="favorite_drink" value="<?= h((string) ($currentUser['favoriteDrink'] ?? '')) ?>" class="w-full bg-black/50 border border-gray-600 rounded p-3 text-white" placeholder="Boisson preferee" />
                  <input name="profile_motto" value="<?= h((string) ($currentUser['profileMotto'] ?? '')) ?>" class="w-full bg-black/50 border border-gray-600 rounded p-3 text-white" placeholder="Phrase perso" />
                  <input type="url" name="banner_url" value="<?= h((string) ($currentUser['bannerUrl'] ?? '')) ?>" class="w-full bg-black/50 border border-gray-600 rounded p-3 text-white" placeholder="Lien banniere profil (optionnel)" />
                  <input type="url" name="avatar_url" class="w-full bg-black/50 border border-gray-600 rounded p-3 text-white" placeholder="Lien image avatar (optionnel)" />

                  <label class="text-xs uppercase tracking-widest text-gray-400">Theme App</label>
                  <select name="theme" class="w-full bg-black/50 border border-gray-600 rounded p-3 text-white">
                    <option value="neon" <?= ($currentUser['theme'] ?? 'neon') === 'neon' ? 'selected' : '' ?>>Theme Neon</option>
                    <option value="gold" <?= ($currentUser['theme'] ?? 'neon') === 'gold' ? 'selected' : '' ?>>Theme Gold</option>
                    <option value="ocean" <?= ($currentUser['theme'] ?? 'neon') === 'ocean' ? 'selected' : '' ?>>Theme Ocean</option>
                    <option value="crimson" <?= ($currentUser['theme'] ?? 'neon') === 'crimson' ? 'selected' : '' ?>>Theme Crimson</option>
                    <option value="frost" <?= ($currentUser['theme'] ?? 'neon') === 'frost' ? 'selected' : '' ?>>Theme Frost</option>
                  </select>

                  <label class="text-xs uppercase tracking-widest text-gray-400">Theme Profil (visible par tous)</label>
                  <select name="profile_theme" class="w-full bg-black/50 border border-gray-600 rounded p-3 text-white">
                    <option value="midnight" <?= ($currentUser['profileTheme'] ?? 'midnight') === 'midnight' ? 'selected' : '' ?>>Midnight</option>
                    <option value="sunset" <?= ($currentUser['profileTheme'] ?? 'midnight') === 'sunset' ? 'selected' : '' ?>>Sunset</option>
                    <option value="emerald" <?= ($currentUser['profileTheme'] ?? 'midnight') === 'emerald' ? 'selected' : '' ?>>Emerald</option>
                    <option value="aurora" <?= ($currentUser['profileTheme'] ?? 'midnight') === 'aurora' ? 'selected' : '' ?>>Aurora</option>
                    <option value="obsidian" <?= ($currentUser['profileTheme'] ?? 'midnight') === 'obsidian' ? 'selected' : '' ?>>Obsidian</option>
                  </select>

                  <label class="text-xs uppercase tracking-widest text-gray-400">Style du nom</label>
                  <select name="name_style" class="w-full bg-black/50 border border-gray-600 rounded p-3 text-white">
                    <option value="default" <?= ($currentUser['nameStyle'] ?? 'default') === 'default' ? 'selected' : '' ?>>Classique</option>
                    <option value="sunfire" <?= ($currentUser['nameStyle'] ?? 'default') === 'sunfire' ? 'selected' : '' ?>>Sunfire</option>
                    <option value="aqua" <?= ($currentUser['nameStyle'] ?? 'default') === 'aqua' ? 'selected' : '' ?>>Aqua Flux</option>
                    <option value="royal" <?= ($currentUser['nameStyle'] ?? 'default') === 'royal' ? 'selected' : '' ?>>Royal Shift</option>
                    <option value="rainbow" <?= ($currentUser['nameStyle'] ?? 'default') === 'rainbow' ? 'selected' : '' ?>>Rainbow Plus</option>
                  </select>

                  <input type="password" name="new_password" class="w-full bg-black/50 border border-gray-600 rounded p-3 text-white" placeholder="Nouveau mot de passe" />
                  <button type="button" id="enableNotifBtn" class="w-full bg-neon-blue/70 border border-neon-blue/40 text-white py-2 rounded font-bold tap-button">Activer notifications</button>

                  <div class="flex gap-2 pt-2">
                    <button type="button" data-close-settings class="flex-1 py-2 text-gray-400 border border-gray-700 rounded tap-button">Annuler</button>
                    <button type="submit" class="flex-1 bg-neon-purple py-2 rounded font-bold text-white tap-button">Sauvegarder</button>
                  </div>
                </form>

                <?php if ($isAdmin): ?>
                  <a href="?page=admin" class="mt-3 block w-full text-center bg-neon-blue/70 text-white py-2 rounded font-bold tap-button">Ouvrir admin</a>
                <?php elseif ($canUnlockAdmin): ?>
                  <form method="post" class="mt-3 js-action-form" data-async="true" data-async-kind="admin-toggle">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
                    <input type="hidden" name="action" value="toggle_admin_mode" />
                    <input type="hidden" name="admin_mode" value="<?= $adminModeEnabled ? '0' : '1' ?>" />
                    <button type="submit" class="w-full <?= $adminModeEnabled ? 'bg-red-700/70 text-red-200 border-red-500/40' : 'bg-yellow-700/70 text-yellow-100 border-yellow-500/40' ?> py-3 rounded font-bold border tap-button">
                      <?= $adminModeEnabled ? 'Desactiver mode admin' : 'Activer mode admin' ?>
                    </button>
                  </form>
                  <?php if ($adminModeEnabled): ?>
                    <a id="openAdminLink" href="?page=admin" class="mt-2 block w-full text-center bg-neon-blue/70 text-white py-2 rounded font-bold tap-button">Ouvrir admin</a>
                  <?php endif; ?>
                <?php endif; ?>

                <form method="post" class="mt-4">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
                  <input type="hidden" name="action" value="logout" />
                  <button type="submit" class="w-full bg-red-900/50 text-red-400 py-3 rounded font-bold border border-red-900 tap-button">Deconnexion</button>
                </form>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($isOwnProfile): ?>
            <div id="challengeModal" class="modal-hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
              <div class="bg-gradient-to-br from-neon-purple to-neon-pink p-1 rounded-2xl w-full max-w-sm">
                <div class="bg-black rounded-xl p-8 text-center">
                  <div class="text-4xl mb-4">🎲</div>
                  <h2 class="text-2xl font-bold text-white mb-4 uppercase font-display">Ton Defi</h2>
                  <p id="challengeText" class="text-lg text-neon-pink font-bold mb-6 min-h-[80px] flex items-center justify-center"><?= h($randomChallenge) ?></p>
                  <button type="button" data-close-challenge class="w-full bg-white text-black font-bold py-3 rounded-full hover:bg-gray-200">J'accepte (ou je bois)</button>
                </div>
              </div>
            </div>
          <?php endif; ?>

          <div class="relative">
            <div class="absolute inset-0 bg-neon-purple blur-3xl opacity-20"></div>
            <div id="profileHeroCard" class="relative <?= h($viewProfileThemeClass) ?> backdrop-blur-xl rounded-3xl p-6 border shadow-2xl">
              <div id="profileBannerWrap" class="<?= $viewBannerUrl !== '' ? '' : 'hidden' ?> mb-4 rounded-2xl overflow-hidden border border-white/10">
                <img id="profileBanner" src="<?= h($viewBannerUrl !== '' ? $viewBannerUrl : 'logo.png') ?>" class="w-full h-28 object-cover" alt="Banniere profil" />
              </div>
              <?php if ($isOwnProfile): ?>
                <div class="absolute top-4 right-4 flex items-center gap-2">
                  <button type="button" id="installAppBtn" class="hidden text-xs bg-neon-blue text-white px-3 py-1 rounded-full font-bold">Installer</button>
                  <button type="button" data-open-settings class="text-gray-400 hover:text-white bg-black/30 p-2 rounded-full">⚙️</button>
                </div>
              <?php endif; ?>

              <div class="flex flex-col items-center mb-6">
                <div class="relative">
                  <img id="profileHeroAvatar" src="<?= h((string) $viewingUser['avatarUrl']) ?>" class="w-24 h-24 rounded-full border-4 border-neon-pink object-cover" alt="Avatar" />
                  <div class="absolute bottom-0 right-0 text-2xl bg-black rounded-full p-1"><?= h((string) $rank['icon']) ?></div>
                </div>
                <h1 id="profileHeroName" class="text-3xl font-display font-bold mt-4 <?= h($viewNameStyleClass) ?>"><?= h((string) $viewingUser['name']) ?></h1>
                <p id="profileHeroClass" class="text-neon-blue font-bold tracking-widest text-xs uppercase"><?= h((string) $viewingUser['className']) ?></p>
                <p id="profileHeroTitle" class="text-xs text-indigo-200 mt-1 uppercase tracking-widest"><?= h($viewProfileTitle) ?></p>
                <p id="profileHeroBio" class="text-xs text-gray-300 mt-2 text-center <?= empty($viewingUser['bio']) ? 'hidden' : '' ?>"><?= h((string) $viewingUser['bio']) ?></p>
                <p id="profileHeroMotto" class="text-[11px] text-gray-200 mt-1 text-center <?= $viewProfileMotto === '' ? 'hidden' : '' ?>">“<?= h($viewProfileMotto) ?>”</p>
                <p id="profileHeroDrink" class="text-[11px] text-cyan-200 mt-1 <?= $viewFavoriteDrink === '' ? 'hidden' : '' ?>">🥤 <?= h($viewFavoriteDrink) ?></p>
              </div>

              <div class="space-y-2">
                <div class="flex justify-between text-sm font-bold">
                  <span class="text-white"><?= h((string) $rank['name']) ?></span>
                  <span class="text-neon-pink"><?= (int) $viewingUser['points'] ?> pts</span>
                </div>
                <div class="h-4 bg-black/50 rounded-full overflow-hidden border border-white/5">
                  <div class="h-full bg-gradient-to-r from-neon-blue to-neon-pink" style="width: <?= (float) $progress ?>%;"></div>
                </div>
                <?php if ($nextRank !== null): ?>
                  <p class="text-xs text-center text-gray-500">Prochain grade a <?= (int) $nextRank['minPoints'] ?> pts</p>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <?php if ($isOwnProfile): ?>
            <button type="button" data-open-challenge class="w-full bg-neon-pink/20 border border-neon-pink/50 p-4 rounded-2xl hover:bg-neon-pink/40 transition-colors animate-pulse-fast flex items-center justify-center gap-3 tap-button">
              <span class="text-3xl">🍻</span>
              <span class="font-bold text-white uppercase tracking-wider">Defi Express</span>
            </button>
          <?php endif; ?>

          <div>
            <h2 class="text-xl font-display font-bold text-white mb-4 pl-2 border-l-4 border-yellow-500">Succes Debloques</h2>
            <div class="grid grid-cols-4 gap-2">
              <?php $achievements = $viewingUser['achievements'] ?? []; ?>
              <?php if ($achievements === []): ?>
                <div class="col-span-4 text-center text-gray-600 py-4 italic">Aucun succes pour l'instant.</div>
              <?php else: ?>
                <?php foreach ($achievements as $achievement): ?>
                  <div class="aspect-square bg-yellow-900/20 border border-yellow-500/30 rounded-lg flex flex-col items-center justify-center p-2 text-center" title="<?= h((string) $achievement['description']) ?>">
                    <div class="text-2xl mb-1"><?= h((string) $achievement['icon']) ?></div>
                    <div class="text-[9px] font-bold text-yellow-500 leading-tight"><?= h((string) $achievement['name']) ?></div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>

          <div>
            <h2 class="text-xl font-display font-bold text-white mb-4 pl-2 border-l-4 border-neon-purple">
              <?= $isOwnProfile ? 'Ton Matos' : 'Le Sac de ' . h((string) $viewingUser['name']) ?>
            </h2>
            <div class="grid grid-cols-2 gap-3">
              <?php $inventory = $viewingUser['inventory'] ?? []; ?>
              <?php if ($inventory === []): ?>
                <div class="col-span-2 text-center py-8 text-gray-600 italic border border-dashed border-gray-800 rounded-xl">Sac vide...</div>
              <?php else: ?>
                <?php foreach ($inventory as $item): ?>
                  <div class="bg-black/40 border border-gray-700 p-3 rounded-xl relative overflow-hidden">
                    <div class="absolute top-0 right-0 px-2 py-0.5 text-[10px] font-bold <?= (string) ($item['rarity'] ?? '') === 'Légendaire' ? 'bg-yellow-500 text-black' : 'bg-gray-700 text-white' ?>">
                      <?= h((string) $item['rarity']) ?>
                    </div>
                    <div class="font-bold text-white mt-4"><?= h((string) $item['name']) ?></div>
                    <div class="text-xs text-gray-400 mt-1"><?= h((string) $item['description']) ?></div>
                    <?php if (!empty($item['imageUrl'])): ?>
                      <img src="<?= h((string) $item['imageUrl']) ?>" class="mt-2 rounded w-full h-24 object-cover border border-white/10" alt="Item" />
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($page === 'parties'): ?>
        <div class="min-h-screen bg-[#0f0e24] pb-28 pt-6 px-4">
          <?php if ($selectedParty === null): ?>
            <div class="flex justify-between items-center mb-8">
              <h1 class="text-3xl font-display font-bold text-white tracking-widest">SOIREES</h1>
              <button type="button" id="toggleCreatePartyForm" class="tap-button bg-neon-pink hover:bg-pink-600 text-white font-bold px-4 py-2 rounded-full shadow-[0_0_15px_rgba(236,72,153,0.5)] active:scale-95 transition-all text-sm">+ CREER</button>
            </div>

            <div id="createPartyCard" class="hidden bg-neon-surface p-4 rounded-xl border border-neon-pink/30 mb-6">
              <h2 class="text-white font-bold mb-3 uppercase text-sm">Nouvelle Soiree</h2>
              <form method="post" enctype="multipart/form-data" class="space-y-3 js-action-form" data-async="true" data-async-kind="party-create-list">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
                <input type="hidden" name="action" value="create_party" />
                <input type="hidden" name="redirect_page" value="parties" />

                <input required name="name" placeholder="Nom de la soiree" class="w-full bg-black/40 border border-gray-600 rounded p-3 text-white focus:border-neon-pink outline-none" />
                <input required name="location_name" placeholder="Lieu" class="w-full bg-black/40 border border-gray-600 rounded p-3 text-white focus:border-neon-pink outline-none" />
                <input type="date" name="date" value="<?= h(date('Y-m-d')) ?>" required class="w-full bg-black/40 border border-gray-600 rounded p-3 text-white focus:border-neon-pink outline-none" />
                <div class="grid grid-cols-2 gap-2">
                  <input name="lat" placeholder="Latitude (optionnel)" class="bg-black/40 border border-gray-600 rounded p-3 text-white focus:border-neon-pink outline-none" />
                  <input name="lng" placeholder="Longitude (optionnel)" class="bg-black/40 border border-gray-600 rounded p-3 text-white focus:border-neon-pink outline-none" />
                </div>
                <label class="block border border-dashed border-gray-600 rounded p-3 text-center text-gray-300 cursor-pointer">
                  Cover image (optionnel)
                  <input type="file" name="party_cover_file" accept="image/*" class="hidden" />
                </label>
                <button type="submit" class="w-full bg-neon-pink text-white font-bold py-2 rounded mt-2 tap-button">CONFIRMER</button>
              </form>
            </div>

            <div id="partiesList" class="space-y-4">
              <?php if ($parties === []): ?>
                <div class="text-gray-500 text-center py-10 italic border border-dashed border-gray-800 rounded-xl">
                  C'est vide ici...<br/>Lance une soiree avec le bouton "CREER" !
                </div>
              <?php else: ?>
                <?php foreach ($parties as $party): ?>
                  <a href="?page=parties&amp;party=<?= h((string) $party['id']) ?>" class="relative h-48 rounded-2xl overflow-hidden cursor-pointer group border border-white/5 active:scale-95 transition-transform block">
                    <img src="<?= h((string) $party['coverUrl']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" alt="Cover" />
                    <div class="absolute inset-0 bg-gradient-to-t from-black via-black/50 to-transparent"></div>
                    <div class="absolute bottom-4 left-4">
                      <h3 class="text-2xl font-display font-bold text-white uppercase"><?= h((string) $party['name']) ?></h3>
                      <p class="text-neon-blue font-bold text-sm">📍 <?= h((string) $party['locationName']) ?></p>
                      <p class="text-gray-400 text-xs"><?= h((string) $party['date']) ?></p>
                    </div>
                  </a>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="fixed inset-0 bg-[#0f0e24] z-50 overflow-y-auto">
              <div class="relative h-64 w-full">
                <img src="<?= h((string) $selectedParty['coverUrl']) ?>" class="w-full h-full object-cover" alt="Cover" />
                <div class="absolute inset-0 bg-gradient-to-t from-[#0f0e24] to-transparent"></div>
                <a href="?page=parties" class="absolute top-4 left-4 bg-black/50 text-white rounded-full p-2 backdrop-blur z-50 flex items-center gap-2 pr-4">
                  <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
                  Retour
                </a>
                <div class="absolute bottom-4 left-4">
                  <h2 class="text-3xl font-display font-bold text-white uppercase"><?= h((string) $selectedParty['name']) ?></h2>
                  <p class="text-neon-blue font-bold flex items-center gap-1">📍 <?= h((string) $selectedParty['locationName']) ?></p>
                  <p class="text-xs text-gray-400 mt-1"><?= h((string) $selectedParty['date']) ?></p>
                </div>
              </div>

              <?php $partyPosts = $postsByParty[$selectedParty['id']] ?? []; ?>
              <div class="p-1 min-h-[50vh]">
                <h3 class="text-center text-white font-bold mb-4 uppercase tracking-widest text-sm border-b border-white/10 pb-2">Souvenirs</h3>
                <?php if ($partyPosts === []): ?>
                  <div class="text-center py-12">
                    <span class="text-4xl">🦗</span>
                    <p class="text-gray-500 mt-2">Aucun media pour l'instant.</p>
                  </div>
                <?php else: ?>
                  <div class="grid grid-cols-3 gap-1">
                    <?php foreach ($partyPosts as $post): ?>
                      <?php
                        $postMedia = app_normalize_post_media($post);
                        $previewMedia = $postMedia[0] ?? null;
                      ?>
                      <div class="relative aspect-square bg-gray-900 group">
                        <?php if (is_array($previewMedia) && ($previewMedia['type'] ?? '') === 'image'): ?>
                          <img src="<?= h((string) ($previewMedia['url'] ?? '')) ?>" class="w-full h-full object-cover" alt="Post" />
                        <?php elseif (is_array($previewMedia) && ($previewMedia['type'] ?? '') === 'video'): ?>
                          <video src="<?= h((string) ($previewMedia['url'] ?? '')) ?>" class="w-full h-full object-cover" muted playsinline preload="metadata"></video>
                          <div class="absolute bottom-1 right-1 text-[10px] px-1.5 py-0.5 bg-black/70 text-white rounded-full">▶</div>
                        <?php else: ?>
                          <div class="w-full h-full flex items-center justify-center p-2 text-[10px] text-gray-400 text-center">"<?= h(app_cut((string) $post['description'], 30)) ?>..."</div>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($page === 'feed'): ?>
        <div class="pb-28 pt-6 px-4 min-h-screen">
          <div class="flex justify-between items-center mb-6">
            <div>
              <h1 class="text-3xl font-display font-bold text-white tracking-wide">LE FIL</h1>
              <p class="text-xs text-neon-blue uppercase tracking-widest">Moments de la confrerie</p>
            </div>
            <button type="button" id="openPostModal" class="tap-button bg-neon-pink hover:bg-pink-600 text-white p-4 rounded-full shadow-[0_0_15px_rgba(236,72,153,0.5)] transition-transform active:scale-90">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 4v16m8-8H4" /></svg>
            </button>
          </div>

          <div id="postModal" class="modal-hidden fixed inset-0 bg-black/90 z-50 flex items-center justify-center p-4">
            <div class="bg-neon-surface w-full max-w-md rounded-2xl p-6 border border-neon-blue/30">
              <h2 class="text-xl font-bold text-white mb-4">Balance ton dossier</h2>
              <form method="post" enctype="multipart/form-data" id="createPostForm" class="space-y-4 js-action-form" data-async="true" data-async-kind="post-create" data-loading-text="Upload en cours...">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
                <input type="hidden" name="action" value="create_post" />

                <div>
                  <select required name="party_id" class="w-full bg-black/40 border border-gray-700 rounded-lg p-3 text-white focus:border-neon-pink outline-none">
                    <option value="">Quelle soiree ?</option>
                    <?php foreach ($parties as $party): ?>
                      <option value="<?= h((string) $party['id']) ?>"><?= h((string) $party['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <textarea required name="description" class="w-full bg-black/40 border border-gray-700 rounded-lg p-3 text-white h-24 focus:border-neon-pink outline-none resize-none" placeholder="Raconte..."></textarea>

                <label class="block border-2 border-dashed border-gray-600 rounded-lg p-4 text-center cursor-pointer hover:border-neon-blue transition-colors">
                  <span class="text-gray-400 text-sm">📸🎬 Ajoute des photos/videos (jusqu'a 8)</span>
                  <input id="postMediaInput" type="file" name="post_media_files[]" accept="image/*,video/*" multiple class="hidden" />
                </label>
                <div id="postMediaPreview" class="hidden">
                  <div class="text-xs text-gray-400 mb-2">Apercu avant publication</div>
                  <div id="postMediaPreviewList" class="media-rail flex gap-2 overflow-x-auto no-scrollbar pb-1"></div>
                </div>

                <div class="flex gap-3 pt-2">
                  <button type="button" id="closePostModal" class="flex-1 py-3 text-gray-400 font-bold tap-button">Annuler</button>
                  <button type="submit" class="flex-1 bg-gradient-to-r from-neon-blue to-neon-purple text-white font-bold py-3 rounded-xl tap-button">Envoyer</button>
                </div>
              </form>
            </div>
          </div>

          <div id="feedList" class="space-y-6">
            <?php if ($posts === []): ?>
              <div class="flex flex-col items-center justify-center py-20 text-gray-600">
                <div class="text-4xl mb-2">😴</div>
                <p>C'est mort ici. Poste un truc !</p>
              </div>
            <?php else: ?>
              <?php foreach ($posts as $post): ?>
                <?php
                  $owner = $userById[$post['userId']] ?? null;
                  $party = null;
                  foreach ($parties as $candidateParty) {
                      if (($candidateParty['id'] ?? '') === ($post['partyId'] ?? '')) {
                          $party = $candidateParty;
                          break;
                      }
                  }
                  $postMedia = app_normalize_post_media($post);
                  $likes = is_array($post['likes']) ? $post['likes'] : [];
                  $liked = in_array((string) $currentUser['id'], $likes, true);
                  $isOwnPost = ((string) ($post['userId'] ?? '')) === ((string) ($currentUser['id'] ?? ''));
                  $canDeletePost = $isOwnPost || $isAdmin;
                ?>
                <div class="fade-in-up card-lift bg-gradient-to-br from-neon-surface/60 to-black/40 rounded-2xl overflow-hidden border border-white/10 shadow-xl" data-post-card-id="<?= h((string) $post['id']) ?>">
                  <?php if ($postMedia !== []): ?>
                    <div class="media-rail mb-2 flex gap-2 overflow-x-auto no-scrollbar px-2 pt-2">
                      <?php foreach ($postMedia as $media): ?>
                        <div class="shrink-0 w-[84%] h-64 rounded-xl overflow-hidden border border-white/10 bg-black/30">
                          <?php if (($media['type'] ?? '') === 'video'): ?>
                            <video src="<?= h((string) ($media['url'] ?? '')) ?>" class="w-full h-full object-cover" controls playsinline preload="metadata"></video>
                          <?php else: ?>
                            <img src="<?= h((string) ($media['url'] ?? '')) ?>" alt="Post" class="w-full h-full object-cover" loading="lazy" />
                          <?php endif; ?>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>

                  <div class="p-4">
                    <div class="flex items-center space-x-3 mb-2">
                      <img src="<?= h((string) ($owner['avatarUrl'] ?? 'logo.png')) ?>" class="w-10 h-10 rounded-full border border-gray-600 object-cover" alt="" />
                      <div>
                        <h3 class="text-white font-bold text-sm"><?= h((string) ($owner['name'] ?? 'Inconnu')) ?></h3>
                        <p class="text-xs text-gray-400">
                          <?= h(date('d/m/Y', (int) $post['timestamp'])) ?> @ <?= h((string) ($party['name'] ?? 'Inconnu')) ?>
                        </p>
                      </div>
                    </div>

                    <p class="text-gray-200 text-sm mb-3"><?= h((string) $post['description']) ?></p>

                    <div class="bg-black/40 rounded-lg p-3 border-l-2 border-neon-pink">
                      <div class="flex justify-between items-center mb-1">
                        <span class="text-neon-pink text-[10px] font-bold uppercase tracking-wider">Evaluation auto</span>
                        <span class="text-white font-bold text-lg">+<?= (int) ($post['pointsAwarded'] ?? 0) ?></span>
                      </div>
                      <p class="text-gray-300 text-xs italic">"<?= h((string) ($post['gmComment'] ?? 'Post publie.')) ?>"</p>
                    </div>

                    <div class="mt-3 flex items-center gap-2">
                      <?php if (!$isOwnPost): ?>
                        <form method="post" class="js-action-form" data-async="true" data-async-kind="like-toggle">
                          <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
                          <input type="hidden" name="action" value="toggle_like" />
                          <input type="hidden" name="post_id" value="<?= h((string) $post['id']) ?>" />
                          <input type="hidden" name="redirect_page" value="feed" />
                          <button type="submit" data-like-post-id="<?= h((string) $post['id']) ?>" class="tap-button text-sm font-bold px-3 py-2 rounded-full border <?= $liked ? 'text-pink-300 border-pink-400 bg-pink-900/30' : 'text-gray-300 border-gray-600 bg-black/30' ?>">
                            <?= $liked ? '❤️' : '🤍' ?> J'aime (<?= count($likes) ?>)
                          </button>
                        </form>
                      <?php else: ?>
                        <button type="button" disabled class="text-sm font-bold px-3 py-2 rounded-full border text-gray-500 border-gray-700 bg-black/30 cursor-not-allowed">
                          🤍 J'aime (<?= count($likes) ?>)
                        </button>
                      <?php endif; ?>

                      <?php if ($canDeletePost): ?>
                        <form method="post" class="js-action-form ml-auto" data-async="true" data-async-kind="post-delete">
                          <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
                          <input type="hidden" name="action" value="delete_post" />
                          <input type="hidden" name="post_id" value="<?= h((string) $post['id']) ?>" />
                          <input type="hidden" name="redirect_page" value="feed" />
                          <button type="submit" class="tap-button text-xs font-bold px-3 py-2 rounded-full border text-red-300 border-red-500/40 bg-red-900/20"><?= $isOwnPost ? 'Supprimer' : 'Suppr admin' ?></button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($page === 'rankings'): ?>
        <div class="p-4 pb-28 pt-6 min-h-screen">
          <h1 class="text-3xl font-display font-bold text-white mb-8 text-center tracking-widest uppercase">Tableau d'Honneur</h1>

          <div class="space-y-4">
            <?php foreach ($rankedUsers as $index => $user): ?>
              <?php $rank = app_get_rank((int) $user['points']); ?>
              <a href="?page=dashboard&amp;view_user=<?= h((string) $user['id']) ?>" class="flex items-center p-4 rounded-xl border relative overflow-hidden cursor-pointer active:scale-95 transition-transform <?= $index === 0 ? 'bg-gradient-to-r from-yellow-900/40 to-black border-yellow-500/50 shadow-[0_0_15px_rgba(234,179,8,0.2)]' : 'bg-neon-surface/30 border-white/5 hover:border-white/20' ?>">
                <div class="w-8 text-center font-display font-bold text-xl <?= $index < 3 ? 'text-white' : 'text-gray-600' ?>">#<?= $index + 1 ?></div>
                <img src="<?= h((string) $user['avatarUrl']) ?>" alt="<?= h((string) $user['name']) ?>" class="w-12 h-12 rounded-full mx-4 border border-gray-600 object-cover" />
                <div class="flex-1 z-10">
                  <h3 class="font-bold text-white text-lg"><?= h((string) $user['name']) ?> <?= $index === 0 ? '<span class="ml-2">👑</span>' : '' ?></h3>
                  <div class="flex items-center text-xs space-x-2"><span class="<?= h((string) $rank['color']) ?> font-bold"><?= h((string) $rank['name']) ?></span></div>
                </div>
                <div class="text-right z-10"><div class="font-display font-bold text-2xl text-neon-blue"><?= (int) $user['points'] ?></div></div>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($page === 'map'): ?>
        <div class="h-full w-full relative bg-[#0f0e24] pb-28 pt-4 px-4">
          <?php if (!(bool) ($data['settings']['isMapEnabled'] ?? true)): ?>
            <div class="h-[70vh] w-full flex items-center justify-center bg-black rounded-2xl border border-red-500/40">
              <div class="text-center p-6 border border-red-500 rounded-xl bg-red-900/20">
                <h1 class="text-2xl text-red-500 font-bold mb-2">Carte Desactivee</h1>
                <p class="text-gray-400">L'admin a coupe le GPS temporairement.</p>
              </div>
            </div>
          <?php else: ?>
            <div class="flex justify-between items-center mb-3">
              <h1 class="text-2xl font-display font-bold drop-shadow-md text-white">Carte Nocturne</h1>
              <button type="button" id="startMapPartyBtn" class="tap-button bg-neon-pink text-white px-4 py-2 rounded-full text-sm font-bold shadow-lg active:scale-95 transition-transform border border-white/20">+ Soiree</button>
            </div>

            <div id="mapPickHint" class="hidden mb-3 bg-neon-pink text-white px-6 py-3 rounded-2xl font-bold shadow-xl border-2 border-white text-center">
              📍 Clique sur la carte pour poser la soiree
            </div>

            <div id="leafletMap"></div>

            <div id="mapPartyModal" class="modal-hidden fixed inset-0 bg-black/90 flex items-center justify-center p-6" style="z-index:12020;">
              <div class="bg-neon-surface w-full max-w-sm p-6 rounded-2xl border border-neon-blue/30 shadow-[0_0_30px_rgba(59,130,246,0.3)]">
                <h2 class="text-xl font-bold mb-4 font-display uppercase">Nouvelle Soiree</h2>
                <p id="pickedCoordsText" class="text-xs text-gray-400 mb-4"></p>

                <form method="post" enctype="multipart/form-data" class="space-y-4 js-action-form" data-async="true" data-async-kind="party-create-map">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
                  <input type="hidden" name="action" value="create_party" />
                  <input type="hidden" name="redirect_page" value="map" />
                  <input type="hidden" id="pickedLatInput" name="lat" />
                  <input type="hidden" id="pickedLngInput" name="lng" />

                  <input name="name" required placeholder="Nom de l'event" class="w-full bg-black/40 border border-gray-600 rounded p-3 text-white focus:border-neon-blue outline-none" />
                  <input name="location_name" required placeholder="Lieu (Nom)" class="w-full bg-black/40 border border-gray-600 rounded p-3 text-white focus:border-neon-blue outline-none" />
                  <input type="date" name="date" value="<?= h(date('Y-m-d')) ?>" required class="w-full bg-black/40 border border-gray-600 rounded p-3 text-white focus:border-neon-blue outline-none" />
                  <label class="block border border-dashed border-gray-600 rounded p-3 text-center text-gray-300 cursor-pointer">
                    Cover image (optionnel)
                    <input type="file" name="party_cover_file" accept="image/*" class="hidden" />
                  </label>

                  <div class="flex gap-2 pt-2">
                    <button type="button" id="cancelMapPartyModal" class="flex-1 text-gray-400 font-bold tap-button">Annuler</button>
                    <button type="submit" class="flex-1 bg-neon-blue text-white font-bold py-2 rounded shadow-lg shadow-blue-900/50 tap-button">Creer</button>
                  </div>
                </form>
              </div>
            </div>

            <div id="mapPartyOverlay" class="modal-hidden fixed inset-0 bg-[#0f0e24] overflow-y-auto no-scrollbar" style="z-index:12010;">
              <div class="relative h-64 w-full">
                <img id="overlayCover" src="logo.png" class="w-full h-full object-cover" alt="Cover" />
                <div class="absolute inset-0 bg-gradient-to-t from-[#0f0e24] to-transparent"></div>
                <button type="button" id="closeMapOverlay" class="absolute top-4 right-4 bg-black/50 text-white rounded-full p-2 hover:bg-black/70 z-50 tap-button">
                  <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
                <div class="absolute bottom-4 left-4">
                  <h2 id="overlayTitle" class="text-3xl font-display font-bold text-white uppercase"></h2>
                  <p id="overlayLocation" class="text-neon-blue font-bold"></p>
                  <p id="overlayDate" class="text-xs text-gray-400 mt-1"></p>
                </div>
              </div>

              <div class="p-2 min-h-[50vh]">
                <h3 class="text-center text-white font-bold mb-4 uppercase tracking-widest text-sm border-b border-white/10 pb-2">Souvenirs</h3>
                <div id="overlayPostsContainer" class="space-y-3"></div>
              </div>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($page === 'admin' && $isAdmin): ?>
        <div class="p-4 pb-28 pt-6 space-y-8">
          <h1 class="text-2xl font-bold text-red-500 mb-2 border-b border-red-900/30 pb-4">Zone Admin</h1>

          <div class="bg-neon-surface p-4 rounded-xl border border-white/10">
            <h2 class="text-lg font-bold text-white mb-4">Sauvegarde & Restauration</h2>
            <div class="grid grid-cols-2 gap-4">
              <a href="?page=admin&amp;download=backup" class="bg-blue-600 hover:bg-blue-500 text-white py-3 rounded-lg font-bold flex flex-col items-center justify-center gap-1">
                <span class="text-xl">⬇️</span>
                <span>Telecharger</span>
              </a>

              <form method="post" enctype="multipart/form-data" id="importBackupForm" class="js-action-form">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
                <input type="hidden" name="action" value="admin_import_backup" />
                <input type="file" name="backup_file" id="backupFileInput" accept=".json,application/json" class="hidden" />
                <label for="backupFileInput" class="bg-gray-700 hover:bg-gray-600 text-white py-3 rounded-lg font-bold flex flex-col items-center justify-center gap-1 cursor-pointer">
                  <span class="text-xl">⬆️</span>
                  <span>Restaurer</span>
                </label>
              </form>
            </div>
            <p class="text-xs text-gray-500 mt-2 text-center">Le fichier contient profils, soirees, posts, objets, succes et reglages.</p>
          </div>

          <div class="bg-neon-surface p-4 rounded-xl border border-white/10">
            <h2 class="text-lg font-bold text-white mb-2">Parametres App</h2>
            <form method="post" class="flex items-center justify-between bg-black/40 p-3 rounded js-action-form" data-async="true" data-async-kind="admin-map-toggle">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
              <input type="hidden" name="action" value="admin_toggle_map" />
              <span class="text-gray-300">Carte Active</span>
              <div class="flex gap-2">
                <button type="submit" name="is_map_enabled" value="1" class="px-4 py-2 rounded font-bold <?= ((bool) ($data['settings']['isMapEnabled'] ?? true)) ? 'bg-green-600 text-white' : 'bg-gray-700 text-white' ?>">OUI</button>
                <button type="submit" name="is_map_enabled" value="0" class="px-4 py-2 rounded font-bold <?= !((bool) ($data['settings']['isMapEnabled'] ?? true)) ? 'bg-red-600 text-white' : 'bg-gray-700 text-white' ?>">NON</button>
              </div>
            </form>
          </div>

          <div class="bg-neon-surface p-4 rounded-xl border border-white/10 space-y-3">
            <h2 class="text-xl font-bold text-white">Points Joueurs</h2>
            <form method="post" class="space-y-3 js-action-form" data-async="true" data-async-kind="admin-generic">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
              <input type="hidden" name="action" value="admin_add_points" />
              <select name="target_user_id" required class="w-full bg-black/40 border border-gray-600 rounded p-2 text-white">
                <option value="">Choisir joueur...</option>
                <?php foreach ($memberUsers as $member): ?>
                  <option value="<?= h((string) $member['id']) ?>"><?= h((string) $member['name']) ?> (<?= (int) $member['points'] ?> pts)</option>
                <?php endforeach; ?>
              </select>
              <input type="number" name="delta_points" required placeholder="Points (+ ou -)" class="w-full bg-black/40 border border-gray-600 rounded p-2 text-white" />
              <input type="text" name="reason" placeholder="Raison (optionnel)" class="w-full bg-black/40 border border-gray-600 rounded p-2 text-white" />
              <button type="submit" class="w-full bg-neon-pink text-white font-bold py-2 rounded">Appliquer</button>
            </form>
          </div>

          <div class="bg-neon-surface p-4 rounded-xl border border-white/10 space-y-3">
            <h2 class="text-xl font-bold text-white">Comptes</h2>
            <form method="post" enctype="multipart/form-data" class="space-y-3 js-action-form" data-async="true" data-async-kind="admin-generic">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
              <input type="hidden" name="action" value="admin_create_user" />
              <input type="text" name="name" required placeholder="Nom" class="w-full bg-black/40 border border-gray-600 rounded p-2 text-white" />
              <input type="text" name="class_name" placeholder="Classe" class="w-full bg-black/40 border border-gray-600 rounded p-2 text-white" />
              <select name="role" class="w-full bg-black/40 border border-gray-600 rounded p-2 text-white">
                <option value="MEMBER">Membre</option>
                <option value="ADMIN">Admin</option>
              </select>
              <input type="password" name="password" placeholder="Mot de passe (obligatoire si admin)" class="w-full bg-black/40 border border-gray-600 rounded p-2 text-white" />
              <input type="url" name="avatar_url" placeholder="Lien avatar (optionnel)" class="w-full bg-black/40 border border-gray-600 rounded p-2 text-white" />
              <label class="block border border-dashed border-gray-600 rounded p-3 text-center text-gray-300 cursor-pointer">
                Avatar (optionnel)
                <input type="file" name="new_user_avatar_file" accept="image/*" class="hidden" />
              </label>
              <button type="submit" class="w-full bg-green-600 text-white font-bold py-2 rounded tap-button">Creer le compte</button>
            </form>

            <form method="post" class="space-y-3 pt-3 border-t border-white/10 js-action-form" data-async="true" data-async-kind="admin-generic">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
              <input type="hidden" name="action" value="admin_delete_user" />
              <select name="target_user_id" required class="w-full bg-black/40 border border-gray-600 rounded p-2 text-white">
                <option value="">Supprimer un compte membre...</option>
                <?php foreach ($memberUsers as $member): ?>
                  <option value="<?= h((string) $member['id']) ?>"><?= h((string) $member['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="w-full bg-red-700 text-white font-bold py-2 rounded tap-button" onclick="return confirm('Confirmer la suppression du compte ?');">Supprimer</button>
            </form>
          </div>

          <div class="bg-neon-surface p-4 rounded-xl border border-yellow-500/20 shadow-lg shadow-yellow-900/10">
            <h2 class="text-xl font-bold text-yellow-500 mb-4 uppercase">Succes</h2>
            <form method="post" class="space-y-3 js-action-form" data-async="true" data-async-kind="admin-achievement-create">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
              <input type="hidden" name="action" value="admin_create_achievement" />
              <div class="flex gap-2">
                <input name="ach_icon" value="🏆" class="w-16 text-center bg-black/40 border border-gray-600 rounded p-2 text-white" />
                <input name="ach_name" required class="flex-1 bg-black/40 border border-gray-600 rounded p-2 text-white" placeholder="Nom du succes" />
              </div>
              <input name="ach_desc" class="w-full bg-black/40 border border-gray-600 rounded p-2 text-white text-xs" placeholder="Description" />
              <button type="submit" class="w-full bg-yellow-500 text-black font-bold px-4 py-2 rounded text-xs tap-button">Creer dans la bibliotheque</button>
            </form>

            <form method="post" class="space-y-2 mt-4 pt-3 border-t border-white/10 js-action-form" data-async="true" data-async-kind="admin-generic">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
              <input type="hidden" name="action" value="admin_assign_achievement" />
              <select name="achievement_id" required class="w-full bg-black/40 border border-gray-600 rounded p-2 text-white text-xs">
                <option value="">Choisir un succes cree...</option>
                <?php foreach (($data['achievementLibrary'] ?? []) as $achItem): ?>
                  <option value="<?= h((string) ($achItem['id'] ?? '')) ?>"><?= h((string) ($achItem['icon'] ?? '🏆')) ?> <?= h((string) ($achItem['name'] ?? 'Succes')) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="flex gap-2">
                <select name="ach_target" required class="flex-1 bg-black/40 border border-gray-600 rounded p-2 text-white text-xs">
                  <option value="">Destinataire...</option>
                  <option value="ALL">🎁 TOUT LE MONDE</option>
                  <?php foreach ($memberUsers as $member): ?>
                    <option value="<?= h((string) $member['id']) ?>"><?= h((string) $member['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="bg-yellow-600 text-black font-bold px-4 rounded text-xs tap-button">Donner</button>
              </div>
            </form>
          </div>

          <div class="bg-neon-surface p-4 rounded-xl border border-neon-purple/30 shadow-lg shadow-purple-900/20">
            <h2 class="text-xl font-bold text-neon-pink mb-4 uppercase">Objets</h2>
            <form method="post" enctype="multipart/form-data" class="space-y-3 js-action-form" data-async="true" data-async-kind="admin-generic">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
              <input type="hidden" name="action" value="admin_create_item" />

              <label class="block border border-dashed border-gray-600 rounded p-3 text-center text-gray-300 cursor-pointer">
                Image objet (optionnel)
                <input type="file" name="item_image_file" accept="image/*" class="hidden" />
              </label>

              <input name="item_name" required class="w-full bg-black/40 border border-gray-600 rounded p-2 text-white" placeholder="Nom de l'objet" />
              <input name="item_desc" class="w-full bg-black/40 border border-gray-600 rounded p-2 text-white" placeholder="Description" />
              <select name="item_rarity" class="w-full bg-black/40 border border-gray-600 rounded p-2 text-white">
                <option value="Commune">Commune</option>
                <option value="Rare">Rare</option>
                <option value="Épique">Epique</option>
                <option value="Légendaire">Legendaire</option>
                <option value="Artefact">Artefact</option>
              </select>

              <div class="flex gap-2">
                <select name="item_target" required class="flex-1 bg-black/40 border border-gray-600 rounded p-2 text-white text-xs">
                  <option value="">Destinataire...</option>
                  <option value="ALL">🎁 TOUT LE MONDE</option>
                  <?php foreach ($memberUsers as $member): ?>
                    <option value="<?= h((string) $member['id']) ?>"><?= h((string) $member['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="bg-green-600 text-white font-bold px-4 rounded text-xs tap-button">Creer & Donner</button>
              </div>
            </form>
          </div>

          <div class="bg-neon-surface p-4 rounded-xl border border-white/10">
            <h2 class="text-lg font-bold text-white mb-4">Defis</h2>
            <form method="post" class="space-y-3 mb-4 js-action-form" data-async="true" data-async-kind="admin-generic">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
              <input type="hidden" name="action" value="admin_create_challenge" />
              <textarea name="challenge_text" required class="w-full bg-black/40 border border-gray-700 rounded p-2 text-white h-20 resize-none" placeholder="Texte du defi"></textarea>
              <div class="flex gap-2">
                <select name="challenge_difficulty" class="flex-1 bg-black/40 border border-gray-700 rounded p-2 text-white">
                  <option value="Facile">Facile</option>
                  <option value="Moyen">Moyen</option>
                  <option value="Hardcore">Hardcore</option>
                </select>
                <button type="submit" class="bg-neon-blue text-white font-bold px-4 rounded">Ajouter</button>
              </div>
            </form>

            <div class="space-y-2 max-h-56 overflow-y-auto no-scrollbar">
              <?php foreach ($data['challenges'] as $challenge): ?>
                <div class="bg-black/40 rounded p-2 flex items-center justify-between gap-2">
                  <div>
                    <div class="text-sm text-white"><?= h((string) $challenge['text']) ?></div>
                    <div class="text-xs text-gray-400"><?= h((string) $challenge['difficulty']) ?></div>
                  </div>
                  <form method="post" class="js-action-form" data-async="true" data-async-kind="admin-generic">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
                    <input type="hidden" name="action" value="admin_delete_challenge" />
                    <input type="hidden" name="challenge_id" value="<?= h((string) $challenge['id']) ?>" />
                    <button type="submit" class="text-red-400 text-xs border border-red-500/40 px-2 py-1 rounded" onclick="return confirm('Supprimer ce defi ?');">Suppr</button>
                  </form>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="bg-neon-surface p-4 rounded-xl border border-pink-500/20">
            <h2 class="text-lg font-bold text-white mb-4">Gestion des Soirees</h2>
            <div class="space-y-3 max-h-[28rem] overflow-y-auto no-scrollbar">
              <?php foreach ($parties as $party): ?>
                <div class="bg-black/40 border border-white/10 rounded-xl p-3" data-party-admin-id="<?= h((string) $party['id']) ?>">
                  <div class="flex items-center gap-3">
                    <img src="<?= h((string) ($party['coverUrl'] ?? 'logo.png')) ?>" alt="Cover" class="w-14 h-14 rounded-lg object-cover border border-white/10" data-party-cover-id="<?= h((string) $party['id']) ?>" />
                    <div class="flex-1 min-w-0">
                      <div class="text-sm font-bold text-white truncate"><?= h((string) $party['name']) ?></div>
                      <div class="text-xs text-gray-400 truncate"><?= h((string) $party['locationName']) ?> · <?= h((string) $party['date']) ?></div>
                    </div>
                  </div>

                  <form method="post" enctype="multipart/form-data" class="mt-3 space-y-2 js-action-form" data-async="true" data-async-kind="admin-party-cover">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
                    <input type="hidden" name="action" value="admin_update_party_cover" />
                    <input type="hidden" name="party_id" value="<?= h((string) $party['id']) ?>" />
                    <input type="url" name="party_cover_url" placeholder="Lien nouvelle photo (optionnel)" class="w-full bg-black/40 border border-gray-700 rounded p-2 text-white text-xs" />
                    <label class="block border border-dashed border-gray-700 rounded p-2 text-center text-gray-300 text-xs cursor-pointer">
                      Importer une nouvelle photo
                      <input type="file" name="party_cover_file" accept="image/*" class="hidden" />
                    </label>
                    <button type="submit" class="w-full text-xs font-bold py-2 rounded bg-neon-blue/80 text-white tap-button">Mettre a jour la photo</button>
                  </form>

                  <form method="post" class="mt-2 js-action-form" data-async="true" data-async-kind="admin-party-delete">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
                    <input type="hidden" name="action" value="admin_delete_party" />
                    <input type="hidden" name="party_id" value="<?= h((string) $party['id']) ?>" />
                    <button type="submit" class="w-full text-xs font-bold py-2 rounded bg-red-700/70 text-red-100 border border-red-500/40 tap-button">Supprimer la soiree</button>
                  </form>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="bg-neon-surface p-4 rounded-xl border border-blue-500/20">
            <h2 class="text-xl font-bold text-blue-400 mb-4 uppercase">Bibliotheque</h2>
            <div class="mb-4">
              <h3 class="text-sm font-bold text-white mb-2 border-b border-gray-700 pb-1">Objets uniques (<?= count($allUniqueItems) ?>)</h3>
              <div class="max-h-40 overflow-y-auto space-y-2 no-scrollbar">
                <?php foreach ($allUniqueItems as $item): ?>
                  <div class="bg-black/40 p-2 rounded flex justify-between items-center gap-2">
                    <div class="flex-1 text-xs">
                      <div class="font-bold text-white"><?= h((string) $item['name']) ?></div>
                      <div class="text-gray-500"><?= h((string) $item['rarity']) ?></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
            <div>
              <h3 class="text-sm font-bold text-white mb-2 border-b border-gray-700 pb-1">Succes uniques (<?= count($allUniqueAchievements) ?>)</h3>
              <div class="max-h-40 overflow-y-auto space-y-2 no-scrollbar">
                <?php foreach ($allUniqueAchievements as $achievement): ?>
                  <div class="bg-black/40 p-2 rounded flex justify-between items-center gap-2">
                    <div class="text-xl"><?= h((string) $achievement['icon']) ?></div>
                    <div class="flex-1 text-xs">
                      <div class="font-bold text-yellow-500"><?= h((string) $achievement['name']) ?></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <nav class="fixed bottom-safe-nav left-3 right-3 bg-neon-surface/90 backdrop-blur-md border border-neon-purple/30 rounded-2xl shadow-2xl z-[160] max-w-md mx-auto">
        <div class="flex justify-around items-center h-[72px] px-2">
          <?php
            $navItems = [
                ['id' => 'feed', 'icon' => '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.3" d="M3 11l9-8 9 8v10a1 1 0 01-1 1h-5v-7H9v7H4a1 1 0 01-1-1V11z"/></svg>'],
                ['id' => 'parties', 'icon' => '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M8 3v18M16 3v18M4 7h16M4 17h16"/></svg>'],
                ['id' => 'map', 'icon' => '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 01.553-.894L9 2m0 18l6-3m-6 3V2m6 15l6 3m-6-3V5m6 15V8"/></svg>'],
                ['id' => 'rankings', 'icon' => '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M4 20V10m6 10V6m6 14v-8m6 8V4"/></svg>'],
                ['id' => 'dashboard', 'icon' => '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM4 21a8 8 0 0116 0"/></svg>'],
            ];
          ?>
          <?php foreach ($navItems as $item): ?>
            <a href="?page=<?= h($item['id']) ?>" class="tap-button flex items-center justify-center w-full h-full transition-all duration-300 <?= $page === $item['id'] ? 'text-neon-pink scale-110 drop-shadow-lg' : 'text-gray-400 hover:text-white' ?>">
              <?= $item['icon'] ?>
            </a>
          <?php endforeach; ?>
        </div>
      </nav>
    </main>
  </div>
<?php endif; ?>

<script>
(function() {
  var appState = {
    map: null,
    mapMarkers: {},
    mapIsPicking: false,
    mapParties: <?= json_encode(array_values($parties), JSON_UNESCAPED_UNICODE) ?>,
    mapPosts: <?= json_encode(array_values($posts), JSON_UNESCAPED_UNICODE) ?>,
    challenges: <?= json_encode(array_values($data['challenges']), JSON_UNESCAPED_UNICODE) ?>,
    pendingNotifications: <?= json_encode(array_values($pendingNotifications), JSON_UNESCAPED_UNICODE) ?>,
    users: <?= json_encode(array_values($users), JSON_UNESCAPED_UNICODE) ?>,
    currentUserId: <?= json_encode((string) ($currentUser['id'] ?? '')) ?>,
    canDeleteAnyPost: <?= $isAdmin ? 'true' : 'false' ?>,
    lastChallengeIdx: -1
  };

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/\"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function showToast(message, type) {
    var id = 'appToast';
    var existing = document.getElementById(id);
    if (existing) existing.remove();
    var toast = document.createElement('div');
    toast.id = id;
    toast.className = 'fixed left-1/2 -translate-x-1/2 top-4 px-4 py-2 rounded-full text-sm font-bold z-[13000] border';
    if (type === 'error') toast.className += ' bg-red-900/90 text-red-100 border-red-500/50';
    else toast.className += ' bg-neon-blue/90 text-white border-neon-blue/40';
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(function() { toast.remove(); }, 2200);
  }

  function lockForm(form, locked) {
    form.dataset.submitting = locked ? '1' : '0';
    var primarySubmit = form.querySelector('button[type="submit"]');
    if (primarySubmit) {
      if (locked) {
        if (!primarySubmit.dataset.prevText) {
          primarySubmit.dataset.prevText = primarySubmit.textContent || '';
        }
        var loadingText = form.getAttribute('data-loading-text');
        if (loadingText) {
          primarySubmit.textContent = loadingText;
        }
        primarySubmit.classList.add('opacity-70');
      } else {
        if (primarySubmit.dataset.prevText) {
          primarySubmit.textContent = primarySubmit.dataset.prevText;
          delete primarySubmit.dataset.prevText;
        }
        primarySubmit.classList.remove('opacity-70');
      }
    }
    form.querySelectorAll('button, input[type="submit"]').forEach(function(el) {
      if (locked) {
        el.setAttribute('disabled', 'disabled');
      } else {
        el.removeAttribute('disabled');
      }
    });
  }

  function closeModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.add('modal-hidden');
  }

  function openModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.remove('modal-hidden');
  }

  function syncProfileUI(user) {
    if (!user) return;
    var heroAvatar = document.getElementById('profileHeroAvatar');
    var heroName = document.getElementById('profileHeroName');
    var heroClass = document.getElementById('profileHeroClass');
    var heroBio = document.getElementById('profileHeroBio');
    var heroTitle = document.getElementById('profileHeroTitle');
    var heroMotto = document.getElementById('profileHeroMotto');
    var heroDrink = document.getElementById('profileHeroDrink');
    var heroCard = document.getElementById('profileHeroCard');
    var bannerWrap = document.getElementById('profileBannerWrap');
    var banner = document.getElementById('profileBanner');
    var preview = document.getElementById('profileAvatarPreview');
    if (heroAvatar && user.avatarUrl) heroAvatar.src = user.avatarUrl;
    if (preview && user.avatarUrl) preview.src = user.avatarUrl;
    if (heroName && typeof user.name === 'string') heroName.textContent = user.name;
    if (heroClass && typeof user.className === 'string') heroClass.textContent = user.className;
    if (heroTitle) heroTitle.textContent = (user.profileTitle || user.className || '').toString();
    if (heroBio) {
      if (user.bio && String(user.bio).trim() !== '') {
        heroBio.textContent = user.bio;
        heroBio.classList.remove('hidden');
      } else {
        heroBio.classList.add('hidden');
      }
    }
    if (heroMotto) {
      if (user.profileMotto && String(user.profileMotto).trim() !== '') {
        heroMotto.textContent = '“' + user.profileMotto + '”';
        heroMotto.classList.remove('hidden');
      } else {
        heroMotto.classList.add('hidden');
      }
    }
    if (heroDrink) {
      if (user.favoriteDrink && String(user.favoriteDrink).trim() !== '') {
        heroDrink.textContent = '🥤 ' + user.favoriteDrink;
        heroDrink.classList.remove('hidden');
      } else {
        heroDrink.classList.add('hidden');
      }
    }
    if (bannerWrap && banner) {
      if (user.bannerUrl && String(user.bannerUrl).trim() !== '') {
        banner.src = String(user.bannerUrl);
        bannerWrap.classList.remove('hidden');
      } else {
        bannerWrap.classList.add('hidden');
      }
    }
    if (heroCard) {
      ['profile-theme-midnight','profile-theme-sunset','profile-theme-emerald','profile-theme-aurora','profile-theme-obsidian']
        .forEach(function(cls) { heroCard.classList.remove(cls); });
      var profileTheme = String(user.profileTheme || 'midnight');
      heroCard.classList.add('profile-theme-' + profileTheme);
    }
    if (heroName) {
      ['name-style-default','name-style-sunfire','name-style-aqua','name-style-royal','name-style-rainbow']
        .forEach(function(cls) { heroName.classList.remove(cls); });
      heroName.classList.add('name-style-' + String(user.nameStyle || 'default'));
    }
    if (typeof user.theme === 'string') {
      document.body.classList.remove('theme-neon', 'theme-gold', 'theme-ocean', 'theme-crimson', 'theme-frost');
      if (user.theme === 'gold') document.body.classList.add('theme-gold');
      else if (user.theme === 'ocean') document.body.classList.add('theme-ocean');
      else if (user.theme === 'crimson') document.body.classList.add('theme-crimson');
      else if (user.theme === 'frost') document.body.classList.add('theme-frost');
      else document.body.classList.add('theme-neon');
    }
  }

  function normalizePostMedia(post) {
    var media = [];
    if (post && Array.isArray(post.media)) {
      post.media.forEach(function(entry) {
        if (!entry) return;
        var type = entry.type === 'video' ? 'video' : 'image';
        var url = String(entry.url || '').trim();
        if (!url) return;
        media.push({ type: type, url: url });
      });
    }
    if (media.length === 0 && post && post.imageUrl) {
      media.push({ type: 'image', url: String(post.imageUrl) });
    }
    return media.slice(0, 8);
  }

  function buildPostMediaHtml(media, heightClass) {
    if (!Array.isArray(media) || media.length === 0) return '';
    var items = media.map(function(entry) {
      var type = entry.type === 'video' ? 'video' : 'image';
      var url = escapeHtml(entry.url || '');
      if (!url) return '';
      if (type === 'video') {
        return '<div class="shrink-0 w-[84%] ' + heightClass + ' rounded-xl overflow-hidden border border-white/10 bg-black/30"><video src="' + url + '" class="w-full h-full object-cover" controls playsinline preload="metadata"></video></div>';
      }
      return '<div class="shrink-0 w-[84%] ' + heightClass + ' rounded-xl overflow-hidden border border-white/10 bg-black/30"><img src="' + url + '" class="w-full h-full object-cover" alt="media" loading="lazy" /></div>';
    }).join('');
    if (!items) return '';
    return '<div class="media-rail mb-2 flex gap-2 overflow-x-auto no-scrollbar px-2 pt-2">' + items + '</div>';
  }

  function appendPostToFeed(payload) {
    var feedList = document.getElementById('feedList');
    if (!feedList || !payload || !payload.post || !payload.owner) return;
    var post = payload.post;
    var owner = payload.owner;
    var party = payload.party || { name: 'Inconnu' };
    var media = normalizePostMedia(post);
    var isOwnPost = String(post.userId || owner.id || '') === String(appState.currentUserId || '');
    var canDeletePost = isOwnPost || Boolean(appState.canDeleteAnyPost);
    var card = document.createElement('div');
    card.className = 'fade-in-up card-lift bg-gradient-to-br from-neon-surface/60 to-black/40 rounded-2xl overflow-hidden border border-white/10 shadow-xl';
    card.setAttribute('data-post-card-id', String(post.id || ''));

    var mediaHtml = buildPostMediaHtml(media, 'h-64');
    var points = Number(post.pointsAwarded || 0);
    var gmComment = escapeHtml(post.gmComment || 'Post publie.');
    var likeBlock = isOwnPost
      ? '<button type="button" disabled class="text-sm font-bold px-3 py-2 rounded-full border text-gray-500 border-gray-700 bg-black/30 cursor-not-allowed">🤍 J\'aime (0)</button>'
      : (
        '<form method="post" class="js-action-form" data-async="true" data-async-kind="like-toggle">' +
          '<input type="hidden" name="csrf" value="<?= h($csrf) ?>" />' +
          '<input type="hidden" name="action" value="toggle_like" />' +
          '<input type="hidden" name="post_id" value="' + escapeHtml(post.id || '') + '" />' +
          '<input type="hidden" name="redirect_page" value="feed" />' +
          '<button type="submit" data-like-post-id="' + escapeHtml(post.id || '') + '" class="tap-button text-sm font-bold px-3 py-2 rounded-full border text-gray-300 border-gray-600 bg-black/30">🤍 J\'aime (0)</button>' +
        '</form>'
      );
    var deleteBlock = canDeletePost
      ? (
        '<form method="post" class="js-action-form ml-auto" data-async="true" data-async-kind="post-delete">' +
          '<input type="hidden" name="csrf" value="<?= h($csrf) ?>" />' +
          '<input type="hidden" name="action" value="delete_post" />' +
          '<input type="hidden" name="post_id" value="' + escapeHtml(post.id || '') + '" />' +
          '<input type="hidden" name="redirect_page" value="feed" />' +
          '<button type="submit" class="tap-button text-xs font-bold px-3 py-2 rounded-full border text-red-300 border-red-500/40 bg-red-900/20">' + (isOwnPost ? 'Supprimer' : 'Suppr admin') + '</button>' +
        '</form>'
      )
      : '';
    card.innerHTML =
      mediaHtml +
      '<div class="p-4">' +
        '<div class="flex items-center space-x-3 mb-2">' +
          '<img src="' + escapeHtml(owner.avatarUrl || 'logo.png') + '" class="w-10 h-10 rounded-full border border-gray-600 object-cover" alt="" />' +
          '<div><h3 class="text-white font-bold text-sm">' + escapeHtml(owner.name || 'Inconnu') + '</h3>' +
          '<p class="text-xs text-gray-400">' + new Date((post.timestamp || 0) * 1000).toLocaleDateString('fr-FR') + ' @ ' + escapeHtml(party.name || 'Inconnu') + '</p></div>' +
        '</div>' +
        '<p class="text-gray-200 text-sm mb-3">' + escapeHtml(post.description || '') + '</p>' +
        '<div class="bg-black/40 rounded-lg p-3 border-l-2 border-neon-pink">' +
          '<div class="flex justify-between items-center mb-1"><span class="text-neon-pink text-[10px] font-bold uppercase tracking-wider">Evaluation auto</span><span class="text-white font-bold text-lg">+' + points + '</span></div>' +
          '<p class="text-gray-300 text-xs italic">"' + gmComment + '"</p>' +
        '</div>' +
        '<div class="mt-3 flex items-center gap-2">' +
          likeBlock +
          deleteBlock +
        '</div>' +
      '</div>';
    feedList.prepend(card);
  }

  function removePostCard(postId) {
    var card = null;
    var targetId = String(postId || '');
    document.querySelectorAll('[data-post-card-id]').forEach(function(candidate) {
      if (!card && candidate.getAttribute('data-post-card-id') === targetId) {
        card = candidate;
      }
    });
    if (!card) return;
    card.style.transition = 'opacity 180ms ease, transform 200ms ease';
    card.style.opacity = '0';
    card.style.transform = 'scale(0.98)';
    setTimeout(function() {
      if (card && card.parentNode) card.parentNode.removeChild(card);
    }, 190);
  }

  function updateLikeButton(postId, likesCount, liked) {
    var btn = null;
    document.querySelectorAll('[data-like-post-id]').forEach(function(candidate) {
      if (!btn && candidate.getAttribute('data-like-post-id') === postId) {
        btn = candidate;
      }
    });
    if (!btn) return;
    btn.textContent = (liked ? '❤️' : '🤍') + " J'aime (" + likesCount + ')';
    btn.className = 'tap-button text-sm font-bold px-3 py-2 rounded-full border ' + (liked ? 'text-pink-300 border-pink-400 bg-pink-900/30' : 'text-gray-300 border-gray-600 bg-black/30');
  }

  function prependPartyCard(party) {
    var list = document.getElementById('partiesList');
    if (!list || !party) return;
    var item = document.createElement('a');
    item.href = '?page=parties&party=' + encodeURIComponent(party.id || '');
    item.className = 'relative h-48 rounded-2xl overflow-hidden cursor-pointer group border border-white/5 active:scale-95 transition-transform block fade-in-up';
    item.innerHTML =
      '<img src="' + escapeHtml(party.coverUrl || 'logo.png') + '" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" alt="Cover" />' +
      '<div class="absolute inset-0 bg-gradient-to-t from-black via-black/50 to-transparent"></div>' +
      '<div class="absolute bottom-4 left-4"><h3 class="text-2xl font-display font-bold text-white uppercase">' + escapeHtml(party.name || 'Soiree') + '</h3>' +
      '<p class="text-neon-blue font-bold text-sm">📍 ' + escapeHtml(party.locationName || 'Lieu') + '</p>' +
      '<p class="text-gray-400 text-xs">' + escapeHtml(party.date || '') + '</p></div>';
    list.prepend(item);
  }

  function initLoginModal() {
    var loginModal = document.getElementById('loginModal');
    var loginUserId = document.getElementById('loginUserId');
    var loginPassword = document.getElementById('loginPassword');
    var loginHint = document.getElementById('loginModalHint');
    var closeLogin = document.getElementById('closeLoginModal');

    document.querySelectorAll('[data-open-login]').forEach(function(btn) {
      btn.addEventListener('click', function() {
        if (!loginModal || !loginUserId || !loginPassword || !loginHint) return;
        var userId = btn.getAttribute('data-user-id') || '';
        var userName = btn.getAttribute('data-user-name') || '';
        var hasPassword = btn.getAttribute('data-user-has-password') === '1';
        loginUserId.value = userId;
        loginPassword.value = '';
        if (hasPassword) {
          loginHint.textContent = userName + ' a un mot de passe: saisis-le pour te connecter.';
          loginPassword.placeholder = 'Mot de passe';
          loginPassword.required = true;
        } else {
          loginHint.textContent = userName + ': premiere connexion sans mot de passe autorisee.';
          loginPassword.placeholder = 'Laisse vide pour premiere connexion';
          loginPassword.required = false;
        }
        loginModal.classList.remove('modal-hidden');
      });
    });

    if (closeLogin && loginModal) {
      closeLogin.addEventListener('click', function() { loginModal.classList.add('modal-hidden'); });
    }
  }

  function initBasicModals() {
    var openSettings = document.querySelector('[data-open-settings]');
    var closeSettings = document.querySelector('[data-close-settings]');
    var settingsModal = document.getElementById('settingsModal');
    if (openSettings && settingsModal) openSettings.addEventListener('click', function() { settingsModal.classList.remove('modal-hidden'); });
    if (closeSettings && settingsModal) closeSettings.addEventListener('click', function() { settingsModal.classList.add('modal-hidden'); });

    var openChallenge = document.querySelector('[data-open-challenge]');
    var closeChallenge = document.querySelector('[data-close-challenge]');
    var challengeModal = document.getElementById('challengeModal');
    var challengeText = document.getElementById('challengeText');
    if (openChallenge && challengeModal) openChallenge.addEventListener('click', function() {
      if (challengeText && Array.isArray(appState.challenges) && appState.challenges.length > 0) {
        var nextIdx = Math.floor(Math.random() * appState.challenges.length);
        if (appState.challenges.length > 1) {
          while (nextIdx === appState.lastChallengeIdx) {
            nextIdx = Math.floor(Math.random() * appState.challenges.length);
          }
        }
        appState.lastChallengeIdx = nextIdx;
        challengeText.textContent = String((appState.challenges[nextIdx] && appState.challenges[nextIdx].text) || 'Defi surprise');
      }
      challengeModal.classList.remove('modal-hidden');
    });
    if (closeChallenge && challengeModal) closeChallenge.addEventListener('click', function() { challengeModal.classList.add('modal-hidden'); });

    var openPostModal = document.getElementById('openPostModal');
    var closePostModal = document.getElementById('closePostModal');
    var postModal = document.getElementById('postModal');
    if (openPostModal && postModal) openPostModal.addEventListener('click', function() { postModal.classList.remove('modal-hidden'); });
    if (closePostModal && postModal) closePostModal.addEventListener('click', function() { postModal.classList.add('modal-hidden'); });

    var postMediaInput = document.getElementById('postMediaInput');
    var postMediaPreview = document.getElementById('postMediaPreview');
    var postMediaPreviewList = document.getElementById('postMediaPreviewList');

    function clearPostMediaPreview() {
      if (!postMediaPreview || !postMediaPreviewList) return;
      var urls = postMediaPreviewList.querySelectorAll('[data-object-url]');
      urls.forEach(function(node) {
        var u = node.getAttribute('data-object-url');
        if (u) URL.revokeObjectURL(u);
      });
      postMediaPreviewList.innerHTML = '';
      postMediaPreview.classList.add('hidden');
    }

    function renderPostMediaPreview(files) {
      if (!postMediaPreview || !postMediaPreviewList) return;
      clearPostMediaPreview();
      if (!files || files.length === 0) return;
      postMediaPreview.classList.remove('hidden');
      Array.prototype.slice.call(files, 0, 8).forEach(function(file) {
        var url = URL.createObjectURL(file);
        var card = document.createElement('div');
        card.className = 'shrink-0 w-28 h-28 rounded-lg overflow-hidden border border-white/10 bg-black/40';
        if ((file.type || '').indexOf('video/') === 0) {
          card.innerHTML = '<video data-object-url="' + escapeHtml(url) + '" src="' + escapeHtml(url) + '" class="w-full h-full object-cover" muted playsinline preload="metadata"></video>';
        } else {
          card.innerHTML = '<img data-object-url="' + escapeHtml(url) + '" src="' + escapeHtml(url) + '" class="w-full h-full object-cover" alt="preview" />';
        }
        postMediaPreviewList.appendChild(card);
      });
    }

    if (postMediaInput) {
      postMediaInput.addEventListener('change', function() {
        renderPostMediaPreview(postMediaInput.files);
      });
    }
    if (closePostModal) {
      closePostModal.addEventListener('click', function() {
        clearPostMediaPreview();
      });
    }

    var createPartyToggle = document.getElementById('toggleCreatePartyForm');
    var createPartyCard = document.getElementById('createPartyCard');
    if (createPartyToggle && createPartyCard) {
      createPartyToggle.addEventListener('click', function() { createPartyCard.classList.toggle('hidden'); });
    }

    var avatarFileInput = document.getElementById('avatarFileInput');
    var profileAvatarPreview = document.getElementById('profileAvatarPreview');
    if (avatarFileInput && profileAvatarPreview) {
      avatarFileInput.addEventListener('change', function() {
        var file = avatarFileInput.files && avatarFileInput.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function(ev) { profileAvatarPreview.src = String(ev.target && ev.target.result ? ev.target.result : profileAvatarPreview.src); };
        reader.readAsDataURL(file);
      });
    }

    var backupFileInput = document.getElementById('backupFileInput');
    var importBackupForm = document.getElementById('importBackupForm');
    if (backupFileInput && importBackupForm) {
      backupFileInput.addEventListener('change', function() {
        if (backupFileInput.files && backupFileInput.files.length > 0) importBackupForm.submit();
      });
    }
  }

  function initPWA() {
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', function() {
        navigator.serviceWorker.register('service-worker.js').catch(function() {});
      });
    }
    var deferredPrompt = null;
    var installBtn = document.getElementById('installAppBtn');
    window.addEventListener('beforeinstallprompt', function(e) {
      e.preventDefault();
      deferredPrompt = e;
      if (installBtn) installBtn.classList.remove('hidden');
    });
    if (installBtn) {
      installBtn.addEventListener('click', async function() {
        if (!deferredPrompt) {
          alert('Sur iPhone: Partager > Ajouter a l\'ecran d\'accueil.');
          return;
        }
        deferredPrompt.prompt();
        await deferredPrompt.userChoice;
        deferredPrompt = null;
        installBtn.classList.add('hidden');
      });
    }
  }

  async function showSystemNotification(title, body) {
    if (!('Notification' in window)) return;
    if (Notification.permission !== 'granted') return;
    try {
      if (navigator.serviceWorker && navigator.serviceWorker.controller) {
        navigator.serviceWorker.controller.postMessage({
          type: 'SHOW_NOTIFICATION',
          title: title,
          body: body
        });
      } else if (navigator.serviceWorker) {
        var reg = await navigator.serviceWorker.ready;
        if (reg && reg.showNotification) {
          reg.showNotification(title, { body: body, icon: 'logo.png', badge: 'logo.png' });
        }
      } else {
        new Notification(title, { body: body, icon: 'logo.png' });
      }
    } catch (_) {}
  }

  async function requestNotificationPermission() {
    if (!('Notification' in window)) {
      showToast('Notifications non supportees sur ce navigateur.', 'error');
      return;
    }
    if (Notification.permission === 'granted') {
      showToast('Notifications deja actives.', 'success');
      return;
    }
    var result = await Notification.requestPermission();
    if (result === 'granted') {
      showToast('Notifications activees.', 'success');
      flushQueuedNotifications();
    } else {
      showToast('Notifications refusees.', 'error');
    }
  }

  function promptNotificationFirstLaunch() {
    if (!('Notification' in window)) return;
    if (Notification.permission !== 'default') return;
    if (!appState.currentUserId) return;
    var key = 'confrerie_notif_prompt_seen_v1';
    try {
      if (localStorage.getItem(key) === '1') return;
      localStorage.setItem(key, '1');
    } catch (_) {}

    var modal = document.createElement('div');
    modal.className = 'fixed inset-0 z-[14000] bg-black/70 backdrop-blur-sm flex items-center justify-center p-4';
    modal.innerHTML =
      '<div class="max-w-sm w-full bg-neon-surface border border-neon-blue/40 rounded-2xl p-5 text-center">' +
        '<div class="text-3xl mb-2">🔔</div>' +
        '<h3 class="text-white font-bold text-lg mb-2">Activer les notifications ?</h3>' +
        '<p class="text-gray-300 text-sm mb-4">Tu seras alerte quand un post sort, ou quand tu recois un succes/objet.</p>' +
        '<div class="flex gap-2">' +
          '<button id="notifLaterBtn" type="button" class="flex-1 py-2 rounded border border-gray-600 text-gray-300 tap-button">Plus tard</button>' +
          '<button id="notifEnableBtn" type="button" class="flex-1 py-2 rounded bg-neon-blue text-white font-bold tap-button">Activer</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(modal);

    var closeModal = function() {
      if (modal && modal.parentNode) modal.parentNode.removeChild(modal);
    };
    var laterBtn = modal.querySelector('#notifLaterBtn');
    var enableBtn = modal.querySelector('#notifEnableBtn');
    if (laterBtn) laterBtn.addEventListener('click', closeModal);
    if (enableBtn) {
      enableBtn.addEventListener('click', async function() {
        await requestNotificationPermission();
        closeModal();
      });
    }
  }

  function flushQueuedNotifications() {
    if (!Array.isArray(appState.pendingNotifications) || appState.pendingNotifications.length === 0) return;
    appState.pendingNotifications.forEach(function(notif) {
      var title = String((notif && notif.title) || 'Notification');
      var body = String((notif && notif.body) || '');
      showSystemNotification(title, body);
    });
    appState.pendingNotifications = [];
  }

  async function pollNotifications() {
    if (!appState.currentUserId) return;
    try {
      var formData = new FormData();
      formData.append('csrf', '<?= h($csrf) ?>');
      formData.append('action', 'poll_notifications');
      var res = await fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
      });
      var ct = res.headers.get('content-type') || '';
      if (ct.indexOf('application/json') === -1) return;
      var json = await res.json();
      if (!json.ok || !json.data || !Array.isArray(json.data.notifications)) return;
      if (!('Notification' in window) || Notification.permission !== 'granted') {
        json.data.notifications.slice(0, 3).forEach(function(notif) {
          showToast(String((notif && notif.title) || 'Notification'), 'success');
        });
        return;
      }
      json.data.notifications.forEach(function(notif) {
        var title = String((notif && notif.title) || 'Notification');
        var body = String((notif && notif.body) || '');
        showSystemNotification(title, body);
      });
    } catch (_) {}
  }

  function initNotifications() {
    var enableBtn = document.getElementById('enableNotifBtn');
    if (enableBtn) {
      enableBtn.addEventListener('click', function() {
        requestNotificationPermission();
      });
    }
    promptNotificationFirstLaunch();
    if ('Notification' in window && Notification.permission === 'granted') {
      flushQueuedNotifications();
    } else if (Array.isArray(appState.pendingNotifications) && appState.pendingNotifications.length > 0) {
      appState.pendingNotifications.slice(0, 3).forEach(function(notif) {
        showToast(String((notif && notif.title) || 'Notification'), 'success');
      });
      appState.pendingNotifications = [];
    }
    if (appState.currentUserId) {
      setTimeout(function() { pollNotifications(); }, 800);
      document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'visible') {
          pollNotifications();
        }
      });
      setInterval(function() {
        pollNotifications();
      }, 4000);
    }
  }

  function initMap() {
    var mapEl = document.getElementById('leafletMap');
    if (!mapEl || typeof L === 'undefined') return;

    var usersMap = {};
    appState.users.forEach(function(u) { usersMap[u.id] = u; });

    var overlay = document.getElementById('mapPartyOverlay');
    var overlayCover = document.getElementById('overlayCover');
    var overlayTitle = document.getElementById('overlayTitle');
    var overlayLocation = document.getElementById('overlayLocation');
    var overlayDate = document.getElementById('overlayDate');
    var overlayPostsContainer = document.getElementById('overlayPostsContainer');
    var closeOverlay = document.getElementById('closeMapOverlay');
    var mapModal = document.getElementById('mapPartyModal');
    var cancelMapModalBtn = document.getElementById('cancelMapPartyModal');
    var startPickBtn = document.getElementById('startMapPartyBtn');
    var pickHint = document.getElementById('mapPickHint');
    var latInput = document.getElementById('pickedLatInput');
    var lngInput = document.getElementById('pickedLngInput');
    var coordsText = document.getElementById('pickedCoordsText');

    if (overlay && overlay.parentNode !== document.body) {
      document.body.appendChild(overlay);
    }
    if (mapModal && mapModal.parentNode !== document.body) {
      document.body.appendChild(mapModal);
    }

    appState.map = L.map('leafletMap', { zoomControl: true, attributionControl: false, zoomAnimation: true }).setView([46.603354, 1.888334], 6);
    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { maxZoom: 19 }).addTo(appState.map);
    setTimeout(function() { appState.map.invalidateSize(); }, 200);
    window.addEventListener('resize', function() { if (appState.map) appState.map.invalidateSize(); });

    function setMapInteractive(enabled) {
      if (!appState.map) return;
      ['dragging','touchZoom','doubleClickZoom','scrollWheelZoom','boxZoom','keyboard'].forEach(function(k) {
        if (!appState.map[k]) return;
        if (enabled) appState.map[k].enable();
        else appState.map[k].disable();
      });
      mapEl.style.pointerEvents = enabled ? 'auto' : 'none';
    }

    function setPicking(enabled) {
      appState.mapIsPicking = Boolean(enabled);
      appState.map.getContainer().style.cursor = appState.mapIsPicking ? 'crosshair' : '';
      if (pickHint) pickHint.classList.toggle('hidden', !appState.mapIsPicking);
      if (startPickBtn) {
        startPickBtn.textContent = appState.mapIsPicking ? 'Annuler' : '+ Soiree';
      }
    }

    function lockMapLayer() {
      document.body.classList.add('map-layer-locked');
      setMapInteractive(false);
    }

    function unlockMapLayer() {
      var overlayOpen = overlay && !overlay.classList.contains('modal-hidden');
      var modalOpen = mapModal && !mapModal.classList.contains('modal-hidden');
      if (!overlayOpen && !modalOpen) {
        document.body.classList.remove('map-layer-locked');
        setMapInteractive(true);
      }
    }

    function renderOverlay(party) {
      if (!overlay || !overlayCover || !overlayTitle || !overlayLocation || !overlayDate || !overlayPostsContainer) return;
      overlayCover.src = party.coverUrl || 'logo.png';
      overlayTitle.textContent = party.name || 'Soiree';
      overlayLocation.textContent = '📍 ' + (party.locationName || 'Lieu inconnu');
      overlayDate.textContent = party.date || '';
      var related = appState.mapPosts.filter(function(post) { return post.partyId === party.id; });
      if (related.length === 0) {
        overlayPostsContainer.innerHTML = '<div class="text-center py-12"><span class="text-4xl">🦗</span><p class="text-gray-500 mt-2">Aucun media pour l\'instant.</p></div>';
      } else {
        overlayPostsContainer.innerHTML = related.map(function(post) {
          var owner = usersMap[post.userId] || { name: 'Inconnu', avatarUrl: 'logo.png' };
          var postMedia = normalizePostMedia(post);
          var mediaHtml = buildPostMediaHtml(postMedia, 'h-56');
          return (
            '<div class="bg-neon-surface/40 rounded-xl border border-white/10 overflow-hidden">' +
              mediaHtml +
              '<div class="p-3">' +
                '<div class="flex items-center gap-2 mb-2"><img src="' + escapeHtml(owner.avatarUrl || 'logo.png') + '" class="w-8 h-8 rounded-full object-cover border border-gray-600" /><span class="text-sm font-bold text-white">' + escapeHtml(owner.name || 'Inconnu') + '</span><span class="ml-auto text-xs text-neon-blue">+' + (post.pointsAwarded || 0) + ' pts</span></div>' +
                '<p class="text-sm text-gray-200">' + escapeHtml(post.description || '') + '</p>' +
              '</div>' +
            '</div>'
          );
        }).join('');
      }
      overlay.classList.remove('modal-hidden');
      lockMapLayer();
    }

    function addPartyMarker(party) {
      if (typeof party.lat !== 'number' || typeof party.lng !== 'number') return;
      var safePartyCover = escapeHtml(party.coverUrl || 'logo.png');
      var iconHtml = "<div style=\"background-image:url('" + safePartyCover + "');width:44px;height:44px;background-size:cover;background-position:center;border-radius:50%;border:3px solid #ec4899;box-shadow:0 0 10px #ec4899;\"></div>";
      var icon = L.divIcon({ className: 'custom-party-marker', html: iconHtml, iconSize: [44, 44], iconAnchor: [22, 22] });
      var marker = L.marker([party.lat, party.lng], { icon: icon }).addTo(appState.map);
      marker.on('click', function(e) {
        L.DomEvent.stopPropagation(e);
        if (!appState.mapIsPicking) renderOverlay(party);
      });
      appState.mapMarkers[party.id] = marker;
    }

    appState.mapParties.forEach(function(party) { addPartyMarker(party); });

    [
      { lat: 48.8566, lng: 2.3522, icon: '🍾', label: 'Planque de vodka' },
      { lat: 45.7640, lng: 4.8357, icon: '🍺', label: 'Mini cave secrete' },
      { lat: 43.2965, lng: 5.3698, icon: '🥃', label: 'Shot mystere' }
    ].forEach(function(egg) {
      var icon = L.divIcon({ className: 'easter-egg-icon', html: '<div style="font-size:20px;">' + egg.icon + '</div>', iconSize: [20, 20], iconAnchor: [10, 10] });
      L.marker([egg.lat, egg.lng], { icon: icon, opacity: 0.8 }).addTo(appState.map).bindPopup(egg.label);
    });

    if (closeOverlay && overlay) {
      closeOverlay.addEventListener('click', function() {
        overlay.classList.add('modal-hidden');
        unlockMapLayer();
      });
      overlay.addEventListener('click', function(evt) {
        if (evt.target === overlay) {
          overlay.classList.add('modal-hidden');
          unlockMapLayer();
        }
      });
    }

    if (startPickBtn) {
      startPickBtn.addEventListener('click', function() {
        setPicking(!appState.mapIsPicking);
      });
    }

    appState.map.on('click', function(e) {
      if (!appState.mapIsPicking) return;
      var lat = e.latlng.lat.toFixed(6);
      var lng = e.latlng.lng.toFixed(6);
      if (latInput) latInput.value = lat;
      if (lngInput) lngInput.value = lng;
      if (coordsText) coordsText.textContent = 'Coordonnees: ' + lat + ', ' + lng;
      openModal('mapPartyModal');
      setPicking(false);
      lockMapLayer();
    });

    if (cancelMapModalBtn && mapModal) {
      cancelMapModalBtn.addEventListener('click', function() {
        mapModal.classList.add('modal-hidden');
        setPicking(false);
        unlockMapLayer();
      });
    }

    appState.addPartyToMap = function(party) {
      appState.mapParties.unshift(party);
      addPartyMarker(party);
      setTimeout(function() { if (appState.map) appState.map.invalidateSize(); }, 100);
    };
    appState.closeMapModal = function() {
      closeModal('mapPartyModal');
      setPicking(false);
      unlockMapLayer();
    };
  }

  document.addEventListener('submit', async function(e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (!form.classList.contains('js-action-form')) return;

    if (form.dataset.submitting === '1') {
      e.preventDefault();
      return;
    }

    var kind = form.getAttribute('data-async-kind') || '';
    if (kind === 'post-delete') {
      var acceptedDelete = window.confirm('Supprimer ce post ?');
      if (!acceptedDelete) {
        e.preventDefault();
        return;
      }
    } else if (kind === 'admin-party-delete') {
      var acceptedPartyDelete = window.confirm('Supprimer cette soiree ? Tous ses posts seront supprimes.');
      if (!acceptedPartyDelete) {
        e.preventDefault();
        return;
      }
    }

    var isAsync = form.getAttribute('data-async') === 'true';
    if (!isAsync) {
      lockForm(form, true);
      return;
    }

    e.preventDefault();
    lockForm(form, true);
    try {
      var response = await fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(form)
      });
      var contentType = response.headers.get('content-type') || '';
      if (contentType.indexOf('application/json') === -1) {
        showToast('Reponse serveur inattendue.', 'error');
        return;
      }
      var json = await response.json();
      if (!json.ok) {
        showToast(json.message || 'Erreur.', 'error');
        return;
      }

      if (kind === 'profile') {
        syncProfileUI(json.data ? json.data.user : null);
        closeModal('settingsModal');
      } else if (kind === 'set-password') {
        var gate = document.getElementById('mustSetPasswordGate');
        if (gate) gate.classList.add('modal-hidden');
        form.reset();
      } else if (kind === 'post-create') {
        appendPostToFeed(json.data || null);
        if (json.data && json.data.post) {
          appState.mapPosts.unshift(json.data.post);
        }
        form.reset();
        var mediaPreview = document.getElementById('postMediaPreview');
        var mediaPreviewList = document.getElementById('postMediaPreviewList');
        if (mediaPreview && mediaPreviewList) {
          mediaPreview.classList.add('hidden');
          mediaPreviewList.innerHTML = '';
        }
        closeModal('postModal');
      } else if (kind === 'like-toggle') {
        if (json.data) {
          updateLikeButton(String(json.data.postId || ''), Number(json.data.likesCount || 0), Boolean(json.data.liked));
          if (json.data.ownerId) {
            appState.users.forEach(function(u) {
              if (u.id === json.data.ownerId) {
                u.points = Number(json.data.ownerPoints || u.points || 0);
              }
            });
          }
        }
      } else if (kind === 'post-delete') {
        if (json.data && json.data.postId) {
          removePostCard(String(json.data.postId));
          appState.mapPosts = appState.mapPosts.filter(function(post) {
            return String(post.id || '') !== String(json.data.postId || '');
          });
        }
      } else if (kind === 'admin-party-delete') {
        if (json.data && json.data.partyId) {
          var targetPartyId = String(json.data.partyId);
          document.querySelectorAll('[data-party-admin-id]').forEach(function(el) {
            if (el.getAttribute('data-party-admin-id') === targetPartyId) {
              el.remove();
            }
          });
          appState.mapParties = appState.mapParties.filter(function(p) {
            return String((p && p.id) || '') !== targetPartyId;
          });
          appState.mapPosts = appState.mapPosts.filter(function(p) {
            return String((p && p.partyId) || '') !== targetPartyId;
          });
        }
      } else if (kind === 'admin-party-cover') {
        if (json.data && json.data.partyId && json.data.coverUrl) {
          var pid = String(json.data.partyId);
          var newCover = String(json.data.coverUrl);
          document.querySelectorAll('[data-party-cover-id]').forEach(function(img) {
            if (img.getAttribute('data-party-cover-id') === pid) {
              img.src = newCover;
            }
          });
          appState.mapParties.forEach(function(p) {
            if (String((p && p.id) || '') === pid) {
              p.coverUrl = newCover;
            }
          });
        }
      } else if (kind === 'party-create-map') {
        if (json.data && json.data.party) {
          if (typeof appState.addPartyToMap === 'function') appState.addPartyToMap(json.data.party);
          prependPartyCard(json.data.party);
          if (typeof appState.closeMapModal === 'function') appState.closeMapModal();
        }
        form.reset();
      } else if (kind === 'party-create-list') {
        if (json.data && json.data.party) prependPartyCard(json.data.party);
        form.reset();
      } else if (kind === 'admin-toggle') {
        var input = form.querySelector('input[name="admin_mode"]');
        var btn = form.querySelector('button[type="submit"]');
        if (input && btn && json.data) {
          var enabled = Boolean(json.data.adminEnabled);
          input.value = enabled ? '0' : '1';
          btn.textContent = enabled ? 'Desactiver mode admin' : 'Activer mode admin';
          btn.className = 'w-full ' + (enabled ? 'bg-red-700/70 text-red-200 border-red-500/40' : 'bg-yellow-700/70 text-yellow-100 border-yellow-500/40') + ' py-3 rounded font-bold border tap-button';
          var existingAdminLink = document.getElementById('openAdminLink');
          if (enabled && !existingAdminLink) {
            var link = document.createElement('a');
            link.id = 'openAdminLink';
            link.href = '?page=admin';
            link.className = 'mt-2 block w-full text-center bg-neon-blue/70 text-white py-2 rounded font-bold tap-button';
            link.textContent = 'Ouvrir admin';
            form.insertAdjacentElement('afterend', link);
          } else if (!enabled && existingAdminLink) {
            existingAdminLink.remove();
          }
        }
      } else if (kind === 'admin-map-toggle') {
        if (json.data) {
          var enabledState = Boolean(json.data.isMapEnabled);
          var yesBtn = form.querySelector('button[name="is_map_enabled"][value="1"]');
          var noBtn = form.querySelector('button[name="is_map_enabled"][value="0"]');
          if (yesBtn && noBtn) {
            yesBtn.className = 'px-4 py-2 rounded font-bold ' + (enabledState ? 'bg-green-600 text-white' : 'bg-gray-700 text-white');
            noBtn.className = 'px-4 py-2 rounded font-bold ' + (!enabledState ? 'bg-red-600 text-white' : 'bg-gray-700 text-white');
          }
        }
      } else if (kind === 'admin-achievement-create') {
        if (json.data && json.data.achievement) {
          var selectLib = document.querySelector('select[name="achievement_id"]');
          if (selectLib) {
            var option = document.createElement('option');
            option.value = String(json.data.achievement.id || '');
            option.textContent = (json.data.achievement.icon || '🏆') + ' ' + (json.data.achievement.name || 'Succes');
            selectLib.prepend(option);
          }
          form.reset();
          var iconField = form.querySelector('input[name="ach_icon"]');
          if (iconField) iconField.value = '🏆';
        }
      }

      showToast(json.message || 'OK', 'success');
    } catch (err) {
      showToast('Erreur reseau.', 'error');
    } finally {
      lockForm(form, false);
    }
  }, true);

  document.querySelectorAll('form').forEach(function(form) {
    if (!form.classList.contains('js-action-form')) {
      form.classList.add('js-action-form');
    }
  });

  initPWA();
  initNotifications();
  initLoginModal();
  initBasicModals();
  initMap();
})();
</script>
</body>
</html>
