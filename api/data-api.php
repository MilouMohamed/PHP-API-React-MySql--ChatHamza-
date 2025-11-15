<?php
// Activer l'affichage des erreurs (pour debug, à enlever en prod)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Pré-vol OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header("Content-Type: application/json; charset=utf-8");
 

 
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = ''; 

$dbName = 'data_hamza_chats';

$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur connexion DB: ' . $mysqli->connect_error]);
    exit();
}

// Méthode HTTP
$method = $_SERVER['REQUEST_METHOD'];

// =================== GET ===================
if ($method === 'GET') {
    $result = $mysqli->query("SELECT * FROM chats ORDER BY id ASC");
    $chats = [];

    $stmtPoids = $mysqli->prepare("SELECT date, valeur FROM poids WHERE chat_id = ? ORDER BY id ASC");
    $stmtPaiements = $mysqli->prepare("SELECT date, details FROM paiements WHERE chat_id = ? ORDER BY id ASC");
    $stmtVermifuges = $mysqli->prepare("SELECT date_admin AS date, remarque FROM vermifuges WHERE chat_id = ? ORDER BY id ASC");
    $stmtVisites = $mysqli->prepare("SELECT date_visite AS date, remarque FROM visites_veterinaires WHERE chat_id = ? ORDER BY id ASC");

    while ($chat = $result->fetch_assoc()) {
        $chatId = $chat['id'];

        $stmtPoids->bind_param('i', $chatId);
        $stmtPoids->execute();
        $chat['poids'] = $stmtPoids->get_result()->fetch_all(MYSQLI_ASSOC);

        $stmtPaiements->bind_param('i', $chatId);
        $stmtPaiements->execute();
        $chat['paiements'] = $stmtPaiements->get_result()->fetch_all(MYSQLI_ASSOC);

        $stmtVermifuges->bind_param('i', $chatId);
        $stmtVermifuges->execute();
        $chat['vermifuges'] = $stmtVermifuges->get_result()->fetch_all(MYSQLI_ASSOC);

        $stmtVisites->bind_param('i', $chatId);
        $stmtVisites->execute();
        $chat['visitesVeterinaires'] = $stmtVisites->get_result()->fetch_all(MYSQLI_ASSOC);

        $chats[] = $chat;
    }

    echo json_encode($chats, JSON_UNESCAPED_UNICODE);
    exit();
}

// DELETE chat
if ($method === 'DELETE') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'ID manquant pour suppression']);
        exit();
    }
    $chatId = (int)$data['id'];
    $mysqli->begin_transaction();
    try {
        $mysqli->query("DELETE FROM chats WHERE id=$chatId");
        $mysqli->query("DELETE FROM poids WHERE chat_id=$chatId");
        $mysqli->query("DELETE FROM paiements WHERE chat_id=$chatId");
        $mysqli->query("DELETE FROM vermifuges WHERE chat_id=$chatId");
        $mysqli->query("DELETE FROM visites_veterinaires WHERE chat_id=$chatId");
        $mysqli->commit();
        echo json_encode(['status' => 'ok']);
    } catch (Exception $e) {
        $mysqli->rollback();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// =================== POST (Ajouter ou Modifier) ===================
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'Payload JSON invalide']);
        exit();
    }

    $mysqli->begin_transaction();

    try {
        foreach ($data as $item) {
            // Gestion des dates vides
            $dateEntree = empty($item['dateEntree']) ? NULL : $item['dateEntree'];
            $dateSortie = empty($item['dateSortie']) ? NULL : $item['dateSortie'];
            $vaccination1 = empty($item['vaccination1']) ? NULL : $item['vaccination1'];
            $vaccination2 = empty($item['vaccination2']) ? NULL : $item['vaccination2'];
            $sterilisation = empty($item['sterilisation']) ? NULL : $item['sterilisation'];

            // Vérifier si l'ID existe (update) ou pas (insert)
            if (isset($item['id']) && !empty($item['id'])) {
                $chatId = $item['id'];
                $stmtUpdateChat = $mysqli->prepare(
                    "UPDATE chats SET nom=?, dateEntree=?, dateSortie=?, vaccination1=?, vaccination2=?, sterilisation=? WHERE id=?"
                );
                $stmtUpdateChat->bind_param(
                    "ssssssi",
                    $item['nom'],
                    $dateEntree,
                    $dateSortie,
                    $vaccination1,
                    $vaccination2,
                    $sterilisation,
                    $chatId
                );
                $stmtUpdateChat->execute();

                // Supprimer les anciens sous-éléments pour remplacer
                $mysqli->query("DELETE FROM poids WHERE chat_id=$chatId");
                $mysqli->query("DELETE FROM paiements WHERE chat_id=$chatId");
                $mysqli->query("DELETE FROM vermifuges WHERE chat_id=$chatId");
                $mysqli->query("DELETE FROM visites_veterinaires WHERE chat_id=$chatId");
            } else {
                // Insert nouveau chat
                $stmtChat = $mysqli->prepare(
                    "INSERT INTO chats (nom, dateEntree, dateSortie, vaccination1, vaccination2, sterilisation) VALUES (?, ?, ?, ?, ?, ?)"
                );
                $stmtChat->bind_param(
                    "ssssss",
                    $item['nom'],
                    $dateEntree,
                    $dateSortie,
                    $vaccination1,
                    $vaccination2,
                    $sterilisation
                );
                $stmtChat->execute();
                $chatId = $mysqli->insert_id;
            }

            // Réinsertion des sous-éléments
            if (!empty($item['poids'])) {
                $stmtPoids = $mysqli->prepare("INSERT INTO poids (chat_id, date, valeur) VALUES (?, ?, ?)");
                foreach ($item['poids'] as $p) {
                    $pDate = empty($p['date']) ? NULL : $p['date'];
                    $stmtPoids->bind_param("iss", $chatId, $pDate, $p['valeur']);
                    $stmtPoids->execute();
                }
            }

            if (!empty($item['paiements'])) {
                $stmtPaiements = $mysqli->prepare("INSERT INTO paiements (chat_id, date, details) VALUES (?, ?, ?)");
                foreach ($item['paiements'] as $pay) {
                    $payDate = empty($pay['date']) ? NULL : $pay['date'];
                    $stmtPaiements->bind_param("iss", $chatId, $payDate, $pay['details']);
                    $stmtPaiements->execute();
                }
            }

            if (!empty($item['vermifuges'])) {
                $stmtVermifuges = $mysqli->prepare("INSERT INTO vermifuges (chat_id, date_admin, remarque) VALUES (?, ?, ?)");
                foreach ($item['vermifuges'] as $v) {
                    $vDate = empty($v['date']) ? NULL : $v['date'];
                    $stmtVermifuges->bind_param("iss", $chatId, $vDate, $v['remarque']);
                    $stmtVermifuges->execute();
                }
            }

            if (!empty($item['visitesVeterinaires'])) {
                $stmtVisites = $mysqli->prepare("INSERT INTO visites_veterinaires (chat_id, date_visite, remarque) VALUES (?, ?, ?)");
                foreach ($item['visitesVeterinaires'] as $v) {
                    $vDate = empty($v['date']) ? NULL : $v['date'];
                    $stmtVisites->bind_param("iss", $chatId, $vDate, $v['remarque']);
                    $stmtVisites->execute();
                }
            }
        }

        $mysqli->commit();
        echo json_encode(['status' => 'ok']);
    } catch (Exception $e) {
        $mysqli->rollback();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }

    exit();
}

// Méthode non supportée
http_response_code(405);
echo json_encode(['error' => 'Méthode non supportée']);
exit();
?>