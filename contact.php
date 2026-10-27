<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Réglages
// ---------------------------------------------------------------------------
const DESTINATAIRE = 'contact@frotek.fr';
const EXPEDITEUR   = 'contact@frotek.fr'; // doit être une adresse de votre domaine hébergé chez OVH
const TAILLE_MAX   = 5 * 1024 * 1024;     // photo : 5 Mo maximum
// ---------------------------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function repondre(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function champ(string $nom, int $max = 2000): string
{
    $v = (isset($_POST[$nom]) && is_string($_POST[$nom])) ? $_POST[$nom] : '';
    $v = str_replace(["\0", "\r"], '', trim($v));
    return mb_substr($v, 0, $max);
}

function une_ligne(string $v): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $v));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    repondre(405, ['ok' => false, 'error' => 'Méthode non autorisée.']);
}

// Anti-spam : champ caché que seuls les robots remplissent
if (champ('website') !== '') {
    repondre(200, ['ok' => true]);
}

$objetsAutorises = [
    "Plan d'inspection",
    'Visite initiale',
    "Plan d'inspection + visite initiale",
    'Audit',
    'Conseil CTP',
    'Autre demande',
];

$nom     = une_ligne(champ('nom', 120));
$societe = une_ligne(champ('societe', 120));
$tel     = une_ligne(champ('tel', 40));
$email   = une_ligne(champ('email', 160));
$objet   = une_ligne(champ('objet', 80));
$message = champ('message', 5000);

$telChiffres = (string) preg_replace('/\D/', '', $tel);
if (
    $nom === '' || $societe === '' || strlen($telChiffres) < 8 || $message === '' ||
    !filter_var($email, FILTER_VALIDATE_EMAIL) ||
    !in_array($objet, $objetsAutorises, true)
) {
    repondre(422, ['ok' => false, 'error' => 'Formulaire incomplet ou invalide. Vérifiez les champs obligatoires.']);
}

// Photo facultative de la plaque signalétique
$photo = null;
if (isset($_FILES['photo']) && is_array($_FILES['photo']) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $f = $_FILES['photo'];
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > TAILLE_MAX) {
        repondre(413, ['ok' => false, 'error' => 'La photo dépasse 5 Mo. Choisissez une image plus légère.']);
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        repondre(400, ['ok' => false, 'error' => "La photo n'a pas pu être envoyée. Réessayez ou retirez-la."]);
    }
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($f['tmp_name']);
    } else {
        $infos = @getimagesize($f['tmp_name']);
        $mime  = is_array($infos) && isset($infos['mime']) ? (string) $infos['mime'] : '';
    }
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
    ];
    if (!isset($extensions[$mime])) {
        repondre(415, ['ok' => false, 'error' => 'Format de photo non accepté. Utilisez une image JPEG, PNG ou WebP.']);
    }
    $contenu = file_get_contents($f['tmp_name']);
    if ($contenu === false) {
        repondre(400, ['ok' => false, 'error' => "La photo n'a pas pu être lue. Réessayez ou retirez-la."]);
    }
    $photo = [
        'mime' => $mime,
        'nom'  => 'plaque-signaletique.' . $extensions[$mime],
        'data' => chunk_split(base64_encode($contenu)),
    ];
}

// Construction du message
$corps  = "Nouvelle demande reçue depuis le site FROTEK\n\n";
$corps .= "Nom : $nom\n";
$corps .= "Société : $societe\n";
$corps .= "E-mail : $email\n";
$corps .= "Téléphone : $tel\n";
$corps .= "Besoin : $objet\n\n";
$corps .= "Message :\n$message\n";
if ($photo !== null) {
    $corps .= "\nPhoto de la plaque signalétique : jointe ({$photo['nom']}).\n";
}

$sujet   = '=?UTF-8?B?' . base64_encode('Demande via le site FROTEK – ' . $objet) . '?=';
$nomMail = '=?UTF-8?B?' . base64_encode($nom) . '?=';

$entetes = [
    'From: FROTEK <' . EXPEDITEUR . '>',
    'Reply-To: ' . $nomMail . ' <' . $email . '>',
    'MIME-Version: 1.0',
];

if ($photo !== null) {
    $limite    = 'frotek_' . bin2hex(random_bytes(12));
    $entetes[] = 'Content-Type: multipart/mixed; boundary="' . $limite . '"';
    $contenuMail =
        "--$limite\r\n" .
        "Content-Type: text/plain; charset=UTF-8\r\n" .
        "Content-Transfer-Encoding: base64\r\n\r\n" .
        chunk_split(base64_encode($corps)) .
        "--$limite\r\n" .
        'Content-Type: ' . $photo['mime'] . '; name="' . $photo['nom'] . "\"\r\n" .
        "Content-Transfer-Encoding: base64\r\n" .
        'Content-Disposition: attachment; filename="' . $photo['nom'] . "\"\r\n\r\n" .
        $photo['data'] . "\r\n" .
        "--$limite--";
} else {
    $entetes[]   = 'Content-Type: text/plain; charset=UTF-8';
    $entetes[]   = 'Content-Transfer-Encoding: 8bit';
    $contenuMail = $corps;
}

$envoye = mail(DESTINATAIRE, $sujet, $contenuMail, implode("\r\n", $entetes), '-f' . EXPEDITEUR);

if ($envoye) {
    repondre(200, ['ok' => true]);
}
repondre(500, ['ok' => false]);
