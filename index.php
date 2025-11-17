<?php
// ================== КОНФИГ ==================
const WB_API_BASE = 'https://advert-api.wildberries.ru';

// ВСТАВЛЕН ТВОЙ ТОКЕН WB (категория «Продвижение»)
const WB_TOKEN   = 'eyJhbGciOiJFUzI1NiIsImtpZCI6IjIwMjUwOTA0djEiLCJ0eXAiOiJKV1QifQ.eyJhY2MiOjEsImVudCI6MSwiZXhwIjoxNzc4NjM3MDU3LCJpZCI6IjAxOWE3MzJmLTE4ZjYtNzBjOC1hYjIzLWRmMjIwZmI1OTczNyIsImlpZCI6MTIxMDIyMTksIm9pZCI6MjE3MDk3LCJzIjo5MCwic2lkIjoiYTgxMGMyNDQtMzU2ZC00MGJlLWI0MzAtNDVkNzVlM2RmOTg4IiwidCI6ZmFsc2UsInVpZCI6MTIxMDIyMTl9.9uc-U8mjH1udN_zs_97JdfBlNc-rfK74UDiUr4vcLBjzrvx6EE_vTZGfCVHh72DCvCESCLak98d0_3LbCtgitg';

// Сколько дней показывать статистику по РК (макс. 31 по доке /adv/v3/fullstats)
const STATS_DAYS = 7;


// ================== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ API ==================

/**
 * Запрос к WB API без cURL — через file_get_contents + stream_context_create
 */
function wb_request(string $method, string $path, array $query = [], $body = null): array
{
    $url = WB_API_BASE . $path;
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    $headers = [
        'Authorization: Bearer ' . WB_TOKEN,
        'Accept: application/json',
    ];

    $content = null;
    if ($body !== null) {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($json);
        $content = $json;
    }

    $options = [
        'http' => [
            'method'        => strtoupper($method),
            'header'        => implode("\r\n", $headers) . "\r\n",
            'content'       => $content,
            'ignore_errors' => true, // чтобы считать тело даже при 4xx/5xx
            'timeout'       => 20,
        ],
    ];

    $context = stream_context_create($options);

    $response = @file_get_contents($url, false, $context);

    // Статус-код
    $status = 0;
    if (isset($http_response_header[0])) {
        if (preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
            $status = (int)$m[1];
        }
    }

    if ($response === false) {
        $error = error_get_last();
        return [
            'ok'     => false,
            'status' => $status,
            'data'   => null,
            'error'  => 'HTTP error or stream error: ' . ($error['message'] ?? 'unknown'),
        ];
    }

    $data = null;
    if ($response !== '' && $response !== null) {
        $decoded = json_decode($response, true);
        $data    = $decoded === null ? $response : $decoded;
    }

    $ok = $status >= 200 && $status < 300;

    return [
        'ok'     => $ok,
        'status' => $status,
        'data'   => $data,
        'error'  => $ok ? null : ('HTTP ' . $status . ' response: ' . (is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE))),
    ];
}

/**
 * Возвращает текст статуса кампании по ID статуса.
 */
function campaign_status_text(int $status): string
{
    switch ($status) {
        case -1: return 'Удалена';
        case 4:  return 'Готова к запуску';
        case 7:  return 'Завершена';
        case 8:  return 'Отменена';
        case 9:  return 'Активна';
        case 11: return 'На паузе';
        default: return 'Неизвестно (' . $status . ')';
    }
}

/**
 * Нормализованный объект кампании для списка.
 * $src = 'auction' (тип 9) или 'promo' (типы 4–8).
 */
function make_campaign_row(array $data, string $src): array
{
    if ($src === 'auction') {
        $id   = (int)($data['id'] ?? 0);
        $type = 9;
        $status = (int)($data['status'] ?? 0);
        $name = $data['settings']['name'] ?? $data['name'] ?? ('Кампания #' . $id);
        $paymentType = $data['settings']['payment_type'] ?? ($data['payment_type'] ?? null);
        $bidType     = $data['bid_type'] ?? null;
        $placements  = $data['placements'] ?? ['search' => false, 'recommendations' => false];
        $timestamps  = $data['timestamps'] ?? [];
    } else {
        // promo 4–8 приходит из /adv/v1/promotion/count
        $id   = (int)($data['advertId'] ?? 0);
        $type = (int)($data['type'] ?? 0);
        $status = (int)($data['status'] ?? 0);
        $name  = 'Кампания #' . $id;
        $paymentType = null;
        $bidType     = null;
        $placements  = ['search' => null, 'recommendations' => null];
        $timestamps  = [
            'created' => null,
            'updated' => $data['changeTime'] ?? null,
            'started' => null,
        ];
    }

    return [
        'id'           => $id,
        'type'         => $type,
        'status'       => $status,
        'name'         => $name,
        'payment_type' => $paymentType,
        'bid_type'     => $bidType,
        'placements'   => $placements,
        'timestamps'   => $timestamps,
        'src'          => $src,
        'raw'          => $data,
    ];
}

/**
 * Получить ВСЕ кампании:
 *  - тип 9 из /adv/v0/auction/adverts (manual/unified)
 *  - типы 4–8 из /adv/v1/promotion/count (минимальная инфа)
 *
 * Возвращает массив [ 'items' => [...], 'error' => string|null ]
 */
function get_all_campaigns_alltypes(): array
{
    $items = [];
    $errors = [];

    // 1) Тип 9 — через /adv/v0/auction/adverts
    $resp9 = wb_request('GET', '/adv/v0/auction/adverts', [
        'statuses'     => '-1,4,7,8,9,11',
        // payment_type можно не указывать, чтобы отдать и cpm, и cpc
    ]);

    if ($resp9['ok'] && isset($resp9['data']['adverts']) && is_array($resp9['data']['adverts'])) {
        foreach ($resp9['data']['adverts'] as $c) {
            $items[] = make_campaign_row($c, 'auction');
        }
    } else {
        if ($resp9['error']) {
            $errors[] = '/adv/v0/auction/adverts: ' . $resp9['error'];
        }
    }

    // 2) Типы 4–8 — минимальная инфа из /adv/v1/promotion/count
    $respOld = wb_request('GET', '/adv/v1/promotion/count');

    if ($respOld['ok'] && isset($respOld['data']['adverts']) && is_array($respOld['data']['adverts'])) {
        foreach ($respOld['data']['adverts'] as $group) {
            $type = (int)($group['type'] ?? 0);
            if ($type < 4 || $type > 8) continue; // нас интересуют только устаревшие типы
            $status = (int)($group['status'] ?? 0);
            if (!isset($group['advert_list']) || !is_array($group['advert_list'])) continue;
            foreach ($group['advert_list'] as $adv) {
                $row = [
                    'advertId'  => $adv['advertId'] ?? 0,
                    'type'      => $type,
                    'status'    => $status,
                    'changeTime'=> $adv['changeTime'] ?? null,
                ];
                $items[] = make_campaign_row($row, 'promo');
            }
        }
    } else {
        if ($respOld['error']) {
            $errors[] = '/adv/v1/promotion/count: ' . $respOld['error'];
        }
    }

    // Сортировка: сначала по дате обновления (если есть), потом по ID
    usort($items, function($a, $b) {
        $ta = $a['timestamps']['updated'] ?? '';
        $tb = $b['timestamps']['updated'] ?? '';
        if ($ta === $tb) {
            return $b['id'] <=> $a['id'];
        }
        return strcmp($tb, $ta);
    });

    $errorText = null;
    if (!empty($errors)) {
        $errorText = implode(" | ", $errors);
    }

    return [
        'items' => $items,
        'error' => $errorText,
    ];
}

/**
 * Получить одну кампанию по ID через /adv/v0/auction/adverts?ids=...
 * Работает только для кампаний типа 9.
 */
function get_campaign_by_id(int $id): ?array
{
    $resp = wb_request('GET', '/adv/v0/auction/adverts', ['ids' => $id]);
    if (!$resp['ok'] || !isset($resp['data']['adverts'][0])) {
        return null;
    }
    return $resp['data']['adverts'][0];
}

/**
 * Бюджет кампании /adv/v1/budget?id=...
 */
function get_campaign_budget(int $id): ?array
{
    $resp = wb_request('GET', '/adv/v1/budget', ['id' => $id]);
    if (!$resp['ok'] || !is_array($resp['data'])) {
        return null;
    }
    return $resp['data'];
}

/**
 * Минимальные ставки на товары (конкурентная ставка) /adv/v0/bids/min
 */
function get_min_bids_for_campaign(int $campaignId, array $nmIds, string $paymentType = 'cpc'): array
{
    if (empty($nmIds)) {
        return [];
    }

    $body = [
        'advert_id'      => $campaignId,
        'nm_ids'         => array_values(array_unique($nmIds)),
        'payment_type'   => $paymentType, // cpm или cpc
        'placement_types'=> ['search'],   // нам нужна ставка для поиска
    ];

    $resp = wb_request('POST', '/adv/v0/bids/min', [], $body);
    if (!$resp['ok'] || !isset($resp['data']['bids']) || !is_array($resp['data']['bids'])) {
        return [];
    }

    $result = []; // nm_id => [placement => value]

    foreach ($resp['data']['bids'] as $item) {
        $nmId = $item['nm_id'] ?? null;
        if (!$nmId || !isset($item['bids']) || !is_array($item['bids'])) continue;
        foreach ($item['bids'] as $b) {
            $type  = $b['type']  ?? null;
            $value = $b['value'] ?? null;
            if ($type && $value !== null) {
                $result[$nmId][$type] = $value;
            }
        }
    }

    return $result;
}

/**
 * Статистика по кампании /adv/v3/fullstats
 */
function get_campaign_stats(int $id, int $days = STATS_DAYS): ?array
{
    $end   = new DateTime('today');
    $start = clone $end;
    $start->modify('-' . max(1, min($days, 31)) . ' days');

    $resp = wb_request('GET', '/adv/v3/fullstats', [
        'ids'       => $id,
        'beginDate' => $start->format('Y-m-d'),
        'endDate'   => $end->format('Y-m-d'),
    ]);

    if (!$resp['ok'] || !is_array($resp['data']) || empty($resp['data'][0])) {
        return null;
    }

    $stats = $resp['data'][0];
    $stats['_beginDate'] = $start->format('Y-m-d');
    $stats['_endDate']   = $end->format('Y-m-d');
    return $stats;
}

/**
 * Изменить статус кампании:
 *  - если активна (9) → пауза /adv/v0/pause
 *  - если пауза (11) или готова к запуску (4) → старт /adv/v0/start
 * Работает только для кампаний типа 9.
 */
function toggle_campaign_status(int $id): array
{
    $campaign = get_campaign_by_id($id);
    if (!$campaign) {
        return [false, 'Кампания не найдена или не является типом 9'];
    }

    $status = (int)($campaign['status'] ?? 0);

    if ($status === 9) {
        // Поставить на паузу
        $resp = wb_request('GET', '/adv/v0/pause', ['id' => $id]);
        if ($resp['ok']) {
            return [true, 'Кампания поставлена на паузу'];
        }
        return [false, 'Ошибка паузы: ' . $resp['error']];
    }

    if ($status === 4 || $status === 11) {
        // Запустить
        $resp = wb_request('GET', '/adv/v0/start', ['id' => $id]);
        if ($resp['ok']) {
            return [true, 'Кампания запущена'];
        }
        return [false, 'Ошибка запуска: ' . $resp['error']];
    }

    return [false, 'Статус кампании не позволяет изменить (текущий: ' . campaign_status_text($status) . ')'];
}

/**
 * Завершить кампанию /adv/v0/stop
 * Работает только для кампаний типа 9.
 */
function stop_campaign(int $id): array
{
    $resp = wb_request('GET', '/adv/v0/stop', ['id' => $id]);
    if ($resp['ok']) {
        return [true, 'Кампания завершена'];
    }
    return [false, 'Ошибка завершения: ' . $resp['error']];
}

/**
 * Установить зоны показов (поиск/рекомендации) через /adv/v0/auction/placements.
 * Принимает желаемое состояние, чтобы можно было включать обе зоны одновременно.
 */
function update_placements(int $campaignId, bool $search, bool $recommendations): array
{
    $campaign = get_campaign_by_id($campaignId);
    if (!$campaign) {
        return [false, 'Кампания не найдена или не является типом 9'];
    }

    $body = [
        'placements' => [
            [
                'advert_id'  => $campaignId,
                'placements' => [
                    'search'          => $search,
                    'recommendations' => $recommendations,
                ],
            ],
        ],
    ];

    $resp = wb_request('PUT', '/adv/v0/auction/placements', [], $body);
    if ($resp['ok']) {
        return [true, 'Зоны показов обновлены'];
    }
    return [false, 'Ошибка смены зон показов: ' . $resp['error']];
}

/**
 * Получить ставки по поисковым кластерам.
 */
function get_normquery_bids(array $items): array
{
    if (empty($items)) return [];

    $resp = wb_request('POST', '/adv/v0/normquery/get-bids', [], ['items' => $items]);
    if (!$resp['ok'] || !isset($resp['data']['bids']) || !is_array($resp['data']['bids'])) {
        return [];
    }
    return $resp['data']['bids'];
}

/**
 * Получить статистику по кластерам за период.
 */
function get_normquery_stats_range(string $from, string $to, array $items): array
{
    if (empty($items)) return [];

    $resp = wb_request('POST', '/adv/v0/normquery/stats', [], [
        'from'  => $from,
        'to'    => $to,
        'items' => $items,
    ]);

    if (!$resp['ok'] || !isset($resp['data']['stats']) || !is_array($resp['data']['stats'])) {
        return [];
    }

    return $resp['data']['stats'];
}

/**
 * Получить минус-фразы по кластерам.
 */
function get_normquery_minus(array $items): array
{
    if (empty($items)) return [];

    $resp = wb_request('POST', '/adv/v0/normquery/get-minus', [], ['items' => $items]);
    if (!$resp['ok'] || !isset($resp['data']['items']) || !is_array($resp['data']['items'])) {
        return [];
    }

    return $resp['data']['items'];
}

/**
 * Установить ставки по кластерам (массово).
 */
function set_normquery_bids(array $bids): array
{
    if (empty($bids)) return [false, 'Нет ставок для обновления'];
    $resp = wb_request('POST', '/adv/v0/normquery/bids', [], ['bids' => $bids]);
    return [$resp['ok'], $resp['error'] ?? null];
}

/**
 * Удалить ставки по кластерам (массово).
 */
function delete_normquery_bids(array $bids): array
{
    if (empty($bids)) return [false, 'Нет ставок для удаления'];
    $resp = wb_request('DELETE', '/adv/v0/normquery/bids', [], ['bids' => $bids]);
    return [$resp['ok'], $resp['error'] ?? null];
}

/**
 * Установить/удалить минус-фразы для кампании+артикула.
 */
function set_normquery_minus(int $advertId, int $nmId, array $phrases): array
{
    $resp = wb_request('POST', '/adv/v0/normquery/set-minus', [], [
        'advert_id'   => $advertId,
        'nm_id'       => $nmId,
        'norm_queries'=> array_values($phrases),
    ]);

    return [$resp['ok'], $resp['error'] ?? null];
}

/**
 * Собрать данные по поисковым кластерам: ставки, статистика, минус-фразы.
 */
function build_clusters_dataset(int $campaignId, array $nmIds, string $from, string $to): array
{
    $items = [];
    foreach ($nmIds as $nmId) {
        $items[] = [
            'advert_id' => $campaignId,
            'nm_id'     => $nmId,
        ];
    }

    $bidsRaw   = get_normquery_bids($items);
    $statsRaw  = get_normquery_stats_range($from, $to, $items);
    $minusRaw  = get_normquery_minus($items);

    $clusters = [];

    foreach ($bidsRaw as $b) {
        $norm = $b['norm_query'] ?? '';
        $nmId = $b['nm_id'] ?? null;
        if ($norm === '' || $nmId === null) continue;
        $key = $nmId . '::' . $norm;
        $clusters[$key] = [
            'nm_id'      => (int)$nmId,
            'advert_id'  => (int)($b['advert_id'] ?? $campaignId),
            'norm_query' => $norm,
            'bid'        => (int)($b['bid'] ?? 0),
            'stats'      => [],
        ];
    }

    foreach ($statsRaw as $row) {
        $nmId = $row['nm_id'] ?? null;
        if ($nmId === null || empty($row['stats']) || !is_array($row['stats'])) continue;
        foreach ($row['stats'] as $stat) {
            $norm = $stat['norm_query'] ?? ($stat['query'] ?? null);
            if ($norm === null) continue;
            $key = $nmId . '::' . $norm;
            if (!isset($clusters[$key])) {
                $clusters[$key] = [
                    'nm_id'      => (int)$nmId,
                    'advert_id'  => (int)($row['advert_id'] ?? $campaignId),
                    'norm_query' => $norm,
                    'bid'        => null,
                    'stats'      => [],
                ];
            }
            $clusters[$key]['stats'] = $stat;
        }
    }

    $minus = [];
    foreach ($minusRaw as $item) {
        $nmId = $item['nm_id'] ?? null;
        if ($nmId === null) continue;
        $minus[$nmId] = $item['norm_queries'] ?? [];
    }

    return [
        'clusters' => $clusters,
        'minus'    => $minus,
    ];
}

/**
 * Обновить ставку по товару в поиске через /adv/v0/auction/bids
 * newBid — в копейках WB (как в API), но мы ожидаем уже целое число.
 */
function update_bid(int $campaignId, int $nmId, string $placement, int $newBid): array
{
    // Ограничим по ТЗ: от 400 до 1500
    $newBid = max(400, min(1500, $newBid));

    $body = [
        'bids' => [
            [
                'advert_id' => $campaignId,
                'nm_bids'   => [
                    [
                        'nm_id'    => $nmId,
                        'bid'      => $newBid,
                        'placement'=> $placement, // search / recommendations / combined
                    ],
                ],
            ],
        ],
    ];

    $resp = wb_request('PATCH', '/adv/v0/auction/bids', [], $body);
    if ($resp['ok']) {
        return [true, 'Ставка обновлена до ' . $newBid];
    }
    return [false, 'Ошибка обновления ставки: ' . $resp['error']];
}


// ================== ОБРАБОТКА ФОРМ ==================

$flashMessage = null;
$flashError   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction  = $_POST['action'] ?? '';
    $campaignId  = isset($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : 0;

    switch ($formAction) {
        case 'toggle_status':
            list($ok, $msg) = toggle_campaign_status($campaignId);
            if ($ok) $flashMessage = $msg; else $flashError = $msg;
            break;

        case 'stop_campaign':
            list($ok, $msg) = stop_campaign($campaignId);
            if ($ok) $flashMessage = $msg; else $flashError = $msg;
            break;

        case 'update_placements':
            $searchState = !empty($_POST['search_state']);
            $recState    = !empty($_POST['recommendations_state']);
            list($ok, $msg) = update_placements($campaignId, $searchState, $recState);
            if ($ok) $flashMessage = $msg; else $flashError = $msg;
            break;

        case 'update_bid':
            $nmId     = isset($_POST['nm_id']) ? (int)$_POST['nm_id'] : 0;
            $placement= $_POST['placement'] ?? 'search';
            $newBid   = isset($_POST['new_bid']) ? (int)$_POST['new_bid'] : 0;
            list($ok, $msg) = update_bid($campaignId, $nmId, $placement, $newBid);
            if ($ok) $flashMessage = $msg; else $flashError = $msg;
            break;

        case 'update_cluster_bid':
            $nmId   = isset($_POST['nm_id']) ? (int)$_POST['nm_id'] : 0;
            $norm   = $_POST['norm_query'] ?? '';
            $newBid = isset($_POST['new_bid']) ? (int)$_POST['new_bid'] : 0;
            $payload = [
                [
                    'advert_id'  => $campaignId,
                    'nm_id'      => $nmId,
                    'norm_query' => $norm,
                    'bid'        => max(400, min(1500, $newBid)),
                ],
            ];
            list($ok, $msg) = set_normquery_bids($payload);
            if ($ok) $flashMessage = 'Ставка по кластеру обновлена'; else $flashError = $msg;
            break;

        case 'delete_cluster_bid':
        case 'reset_cluster_bid':
            $nmId = isset($_POST['nm_id']) ? (int)$_POST['nm_id'] : 0;
            $norm = $_POST['norm_query'] ?? '';
            $payload = [
                [
                    'advert_id'  => $campaignId,
                    'nm_id'      => $nmId,
                    'norm_query' => $norm,
                    'bid'        => 0,
                ],
            ];
            list($ok, $msg) = delete_normquery_bids($payload);
            if ($ok) {
                $flashMessage = $formAction === 'reset_cluster_bid' ? 'Ставка возвращена к базовой' : 'Кластер отключён';
            } else {
                $flashError = $msg;
            }
            break;

        case 'update_minus_phrases':
            $nmId   = isset($_POST['nm_id']) ? (int)$_POST['nm_id'] : 0;
            $raw    = $_POST['minus_phrases'] ?? '';
            $phrases = array_filter(array_map('trim', preg_split('/\r?\n/', $raw)));
            list($ok, $msg) = set_normquery_minus($campaignId, $nmId, $phrases);
            if ($ok) $flashMessage = 'Минус-фразы обновлены'; else $flashError = $msg;
            break;
    }
}

// ================== ВЫБОР ВИДА (СПИСОК / КОМПАНИЯ) ==================

$view      = $_GET['view'] ?? 'list';
$currentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>WB Реклама — дашборд</title>
    <style>
        :root {
            --bg-main: #202225;
            --bg-card: #2f3136;
            --bg-card-soft: #36393f;
            --accent: #5865F2;
            --accent-soft: #4752c4;
            --accent-danger: #ed4245;
            --accent-success: #3ba55d;
            --text-main: #ffffff;
            --text-muted: #b9bbbe;
            --border-soft: #4f545c;
            --radius-lg: 12px;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: var(--bg-main);
            color: var(--text-main);
        }

        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px 16px 40px;
        }

        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }

        .title {
            font-size: 24px;
            font-weight: 700;
        }

        .subtitle {
            font-size: 14px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 16px 20px;
            margin-bottom: 16px;
            border: 1px solid var(--border-soft);
        }

        .chip {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .04em;
            border: 1px solid var(--border-soft);
            color: var(--text-muted);
        }

        .chip.success { border-color: var(--accent-success); color: var(--accent-success); }
        .chip.warning { border-color: #faa61a; color: #faa61a; }
        .chip.danger  { border-color: var(--accent-danger); color: var(--accent-danger); }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 12px;
            border-radius: 999px;
            border: none;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            background: var(--accent);
            color: #fff;
            transition: background .15s ease, transform .05s ease;
        }

        .btn:hover { background: var(--accent-soft); transform: translateY(-1px); }
        .btn:active { transform: translateY(0); }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--border-soft);
            color: var(--text-main);
        }

        .btn-danger {
            background: var(--accent-danger);
        }

        .btn-danger:hover {
            background: #b42428;
        }

        .btn-success {
           	background: var(--accent-success);
        }

        .btn-success:hover {
            background: #2d7d46;
        }

        .status-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }

        .campaign-list {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .campaign-list th,
        .campaign-list td {
            padding: 8px 8px;
            border-bottom: 1px solid #373a3f;
        }

        .campaign-list th {
            text-align: left;
            color: var(--text-muted);
            font-weight: 500;
            font-size: 12px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 999px;
            background: var(--bg-card-soft);
            color: var(--text-muted);
            font-size: 11px;
        }

        .flash {
            padding: 10px 14px;
            border-radius: var(--radius-lg);
            margin-bottom: 16px;
            font-size: 13px;
        }

        .flash.success {
            background: rgba(59,165,93,0.16);
            border: 1px solid var(--accent-success);
        }

        .flash.error {
            background: rgba(237,66,69,0.16);
            border: 1px solid var(--accent-danger);
        }

        .flex {
            display: flex;
        }

        .flex-gap {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .flex-space {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .mt-8 { margin-top: 8px; }
        .mt-12 { margin-top: 12px; }
        .mt-16 { margin-top: 16px; }
        .mt-24 { margin-top: 24px; }

        .muted { color: var(--text-muted); font-size: 12px; }

        .grid-2 {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr);
            gap: 16px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }

        .table th,
        .table td {
            padding: 6px 6px;
            border-bottom: 1px solid #40444b;
        }

        .table th {
            text-align: left;
            color: var(--text-muted);
            font-weight: 500;
        }

        .input {
            background: var(--bg-card-soft);
            border-radius: 999px;
            border: 1px solid var(--border-soft);
            padding: 4px 10px;
            color: var(--text-main);
            font-size: 12px;
            width: 80px;
        }

        .input:focus {
            outline: none;
            box-shadow: 0 0 0 1px var(--accent);
            border-color: var(--accent);
        }

        /* Переключатели (ползунки) для зон показов */
        .switch {
            position: relative;
            display: inline-block;
            width: 42px;
            height: 22px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #72767d;
            transition: .2s;
            border-radius: 999px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .2s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: var(--accent);
        }

        input:checked + .slider:before {
            transform: translateX(18px);
        }

        .clusters-header {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
        }

        .clusters-tools {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        .clusters-table {
            width: 100%;
            border-collapse: collapse;
        }

        .clusters-table th,
        .clusters-table td {
            padding: 8px 6px;
            border-bottom: 1px solid #40444b;
            font-size: 13px;
        }

        .clusters-table th {
            color: var(--text-muted);
            text-align: left;
            font-weight: 500;
        }

        .pill-input {
            background: var(--bg-card-soft);
            border: 1px solid var(--border-soft);
            border-radius: 8px;
            padding: 6px 10px;
            color: var(--text-main);
        }

        .cluster-actions {
            display: flex;
            gap: 6px;
            align-items: center;
        }

        .muted-small {
            color: var(--text-muted);
            font-size: 12px;
        }

        @media (max-width: 900px) {
            .grid-2 {
                grid-template-columns: minmax(0, 1fr);
            }
        }
    </style>
</head>
<body>
<div class="container">

    <div class="header">
        <div>
            <div class="title">WB Реклама — дашборд</div>
            <div class="subtitle">
                Управление рекламными кампаниями, ставками и статистикой по API WB
            </div>
        </div>
        <div>
            <?php if ($view !== 'list'): ?>
                <a href="?view=list" class="btn btn-outline">← Ко всем кампаниям</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($flashMessage): ?>
        <div class="flash success"><?= htmlspecialchars($flashMessage) ?></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="flash error"><?= htmlspecialchars($flashError) ?></div>
    <?php endif; ?>

    <?php if ($view === 'campaign' && $currentId > 0): ?>

        <?php
        $campaign = get_campaign_by_id($currentId);
        if (!$campaign): ?>
            <div class="card">
                Кампания с ID <?= htmlspecialchars($currentId) ?> не найдена через /adv/v0/auction/adverts.<br>
                Это может быть кампания старого типа (4–8) или токен не даёт доступ к продвижению.
            </div>
        <?php else:
            $status = (int)($campaign['status'] ?? 0);
            $statusText = campaign_status_text($status);
            $paymentType = $campaign['settings']['payment_type'] ?? ($campaign['payment_type'] ?? '—');
            $bidType     = $campaign['bid_type'] ?? '—';
            $placements  = $campaign['placements'] ?? ['search' => false, 'recommendations' => false];

            $budget = get_campaign_budget($currentId);
            $budgetTotal = $budget['total'] ?? null;

            $nmSettings = $campaign['nm_settings'] ?? [];
            $nmIds = [];
            foreach ($nmSettings as $nm) {
                if (isset($nm['nm_id'])) {
                    $nmIds[] = (int)$nm['nm_id'];
                }
            }
            $minBids = get_min_bids_for_campaign($currentId, $nmIds, $paymentType);

            $stats = get_campaign_stats($currentId, STATS_DAYS);

            $clusterFrom = $_GET['cluster_from'] ?? (new DateTime('-6 days'))->format('Y-m-d');
            $clusterTo   = $_GET['cluster_to']   ?? (new DateTime('today'))->format('Y-m-d');
            $clusterDataset = build_clusters_dataset($currentId, $nmIds, $clusterFrom, $clusterTo);
            $clusters = $clusterDataset['clusters'];
            ksort($clusters);
            $minusMap = $clusterDataset['minus'];
        ?>

        <div class="card">
            <div class="flex-space">
                <div>
                    <div class="title" style="font-size: 20px;"><?= htmlspecialchars($campaign['settings']['name'] ?? $campaign['name'] ?? ('Кампания #' . $currentId)) ?></div>
                    <div class="status-row">
                        <span class="chip <?= $status === 9 ? 'success' : ($status === 11 ? 'warning' : '') ?>">
                            Статус: <?= htmlspecialchars($statusText) ?>
                        </span>
                        <span class="chip">
                            ID: <?= htmlspecialchars($currentId) ?>
                        </span>
                        <span class="chip">
                            Тип кампании: 9 (auction)
                        </span>
                        <span class="chip">
                            Тип ставки: <?= htmlspecialchars($bidType) ?> (<?= htmlspecialchars($paymentType) ?>)
                        </span>
                    </div>
                </div>
                <div class="flex-gap">
                    <form method="post">
                        <input type="hidden" name="action" value="toggle_status">
                        <input type="hidden" name="campaign_id" value="<?= htmlspecialchars($currentId) ?>">
                        <?php if ($status === 9): ?>
                            <button class="btn btn-outline" type="submit">Поставить на паузу</button>
                        <?php elseif ($status === 4 || $status === 11): ?>
                            <button class="btn btn-success" type="submit">Запустить кампанию</button>
                        <?php else: ?>
                            <button class="btn btn-outline" type="submit" disabled>Статус нельзя изменить</button>
                        <?php endif; ?>
                    </form>

                    <form method="post" onsubmit="return confirm('Точно завершить кампанию? Это действие необратимо.');">
                        <input type="hidden" name="action" value="stop_campaign">
                        <input type="hidden" name="campaign_id" value="<?= htmlspecialchars($currentId) ?>">
                        <button class="btn btn-danger" type="submit">Завершить</button>
                    </form>
                </div>
            </div>

            <div class="mt-12 muted">
                Создана: <?= htmlspecialchars($campaign['timestamps']['created'] ?? '—') ?> ·
                Обновлена: <?= htmlspecialchars($campaign['timestamps']['updated'] ?? '—') ?> ·
                Запущена: <?= htmlspecialchars($campaign['timestamps']['started'] ?? '—') ?>
            </div>

            <div class="mt-12">
                <span class="badge">
                    Бюджет кампании:
                    &nbsp;<strong><?= $budgetTotal !== null ? number_format($budgetTotal, 0, ',', ' ') . ' ₽' : 'нет данных' ?></strong>
                </span>
            </div>
        </div>

        <div class="grid-2 mt-16">
            <!-- ЗОНЫ ПОКАЗОВ -->
            <div class="card">
                <div class="flex-space">
                    <div><strong>Зоны показов</strong></div>
                </div>
                <form method="post" id="placements-form" class="mt-12">
                    <input type="hidden" name="action" value="update_placements">
                    <input type="hidden" name="campaign_id" value="<?= htmlspecialchars($currentId) ?>">
                    <input type="hidden" name="search_state" id="placements-search-state" value="<?= !empty($placements['search']) ? 1 : 0 ?>">
                    <input type="hidden" name="recommendations_state" id="placements-rec-state" value="<?= !empty($placements['recommendations']) ? 1 : 0 ?>">

                    <div class="flex-space">
                        <span>Поиск</span>
                        <label class="switch">
                            <input type="checkbox" id="placement-search" <?= !empty($placements['search']) ? 'checked' : '' ?> onchange="syncPlacementsAndSubmit()">
                            <span class="slider"></span>
                        </label>
                    </div>
                    <div class="flex-space mt-8">
                        <span>Рекомендации</span>
                        <label class="switch">
                            <input type="checkbox" id="placement-recommendations" <?= !empty($placements['recommendations']) ? 'checked' : '' ?> onchange="syncPlacementsAndSubmit()">
                            <span class="slider"></span>
                        </label>
                    </div>
                    <div class="mt-12 muted">
                        Зоны теперь устанавливаются явно — можно включать поиск и рекомендации одновременно.
                    </div>
                </form>
            </div>

            <!-- КРАТКАЯ ИНФА -->
            <div class="card">
                <strong>Общая информация</strong>
                <div class="mt-8" style="font-size: 13px;">
                    <div>Тип кампании: <span class="muted">9 (ручная/единая ставка)</span></div>
                    <div>Модель оплаты: <span class="muted"><?= htmlspecialchars($paymentType) ?></span></div>
                    <div>Тип ставки: <span class="muted"><?= htmlspecialchars($bidType) ?></span></div>
                    <div>Всего товаров в кампании: <span class="muted"><?= count($nmSettings) ?></span></div>
                </div>
            </div>
        </div>

        <!-- ТОП ПОИСКОВЫХ КЛАСТЕРОВ -->
        <div class="card mt-16">
            <div class="clusters-header">
                <div>
                    <div class="title" style="font-size: 18px;">Топ поисковых кластеров</div>
                    <div class="muted-small">Отображаются включённые и выключенные кластеры. Можно редактировать ставку CRM (400–1500).</div>
                </div>
                <div class="clusters-tools">
                    <input type="text" id="cluster-filter" class="pill-input" placeholder="Искать кластер...">
                    <form method="get" class="clusters-tools">
                        <input type="hidden" name="view" value="campaign">
                        <input type="hidden" name="id" value="<?= htmlspecialchars($currentId) ?>">
                        <input type="date" name="cluster_from" value="<?= htmlspecialchars($clusterFrom) ?>" class="pill-input">
                        <input type="date" name="cluster_to" value="<?= htmlspecialchars($clusterTo) ?>" class="pill-input">
                        <button class="btn" type="submit">Обновить период</button>
                    </form>
                </div>
            </div>

            <?php if (empty($clusters)): ?>
                <div class="mt-12 muted">Кластеры не найдены для указанных артикулов.</div>
            <?php else: ?>
                <div class="mt-12" style="overflow-x:auto;">
                    <table class="clusters-table" id="clusters-table">
                        <thead>
                        <tr>
                            <th>Кластер</th>
                            <th>Ставка CRM</th>
                            <th>Средняя позиция</th>
                            <th>Показы</th>
                            <th>Клики</th>
                            <th>Корзина</th>
                            <th>Заказы</th>
                            <th>Управление кластерами</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($clusters as $c): ?>
                            <?php
                            $stat = $c['stats'] ?? [];
                            $avgPos = $stat['avg_position'] ?? ($stat['avgPosition'] ?? 'н/д');
                            $shows  = $stat['shows'] ?? ($stat['views'] ?? 'н/д');
                            $clicks = $stat['clicks'] ?? 'н/д';
                            $cart   = $stat['cart'] ?? ($stat['baskets'] ?? 'н/д');
                            $orders = $stat['orders'] ?? 'н/д';
                            ?>
                            <tr data-cluster="<?= htmlspecialchars(mb_strtolower($c['norm_query'])) ?>">
                                <td>
                                    <div><strong><?= htmlspecialchars($c['norm_query']) ?></strong></div>
                                    <div class="muted-small">Артикул: <?= htmlspecialchars($c['nm_id']) ?></div>
                                </td>
                                <td>
                                    <form method="post" class="cluster-actions">
                                        <input type="hidden" name="action" value="update_cluster_bid">
                                        <input type="hidden" name="campaign_id" value="<?= htmlspecialchars($currentId) ?>">
                                        <input type="hidden" name="nm_id" value="<?= htmlspecialchars($c['nm_id']) ?>">
                                        <input type="hidden" name="norm_query" value="<?= htmlspecialchars($c['norm_query']) ?>">
                                        <input type="number" name="new_bid" min="400" max="1500" class="input" value="<?= htmlspecialchars($c['bid'] ?? 400) ?>">
                                        <button class="btn" type="submit">Сохранить</button>
                                    </form>
                                </td>
                                <td><?= htmlspecialchars($avgPos) ?></td>
                                <td><?= htmlspecialchars($shows) ?></td>
                                <td><?= htmlspecialchars($clicks) ?></td>
                                <td><?= htmlspecialchars($cart) ?></td>
                                <td><?= htmlspecialchars($orders) ?></td>
                                <td>
                                    <div class="cluster-actions">
                                        <form method="post">
                                            <input type="hidden" name="action" value="delete_cluster_bid">
                                            <input type="hidden" name="campaign_id" value="<?= htmlspecialchars($currentId) ?>">
                                            <input type="hidden" name="nm_id" value="<?= htmlspecialchars($c['nm_id']) ?>">
                                            <input type="hidden" name="norm_query" value="<?= htmlspecialchars($c['norm_query']) ?>">
                                            <button class="btn btn-outline" type="submit">Отключить</button>
                                        </form>
                                        <form method="post">
                                            <input type="hidden" name="action" value="update_cluster_bid">
                                            <input type="hidden" name="campaign_id" value="<?= htmlspecialchars($currentId) ?>">
                                            <input type="hidden" name="nm_id" value="<?= htmlspecialchars($c['nm_id']) ?>">
                                            <input type="hidden" name="norm_query" value="<?= htmlspecialchars($c['norm_query']) ?>">
                                            <input type="hidden" name="new_bid" value="<?= htmlspecialchars(max(400, (int)($c['bid'] ?? 400))) ?>">
                                            <button class="btn btn-success" type="submit">Включить</button>
                                        </form>
                                        <form method="post">
                                            <input type="hidden" name="action" value="reset_cluster_bid">
                                            <input type="hidden" name="campaign_id" value="<?= htmlspecialchars($currentId) ?>">
                                            <input type="hidden" name="nm_id" value="<?= htmlspecialchars($c['nm_id']) ?>">
                                            <input type="hidden" name="norm_query" value="<?= htmlspecialchars($c['norm_query']) ?>">
                                            <button class="btn btn-outline" type="submit">Вернуть базовую ставку</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- МИНУС-ФРАЗЫ -->
        <div class="card">
            <div class="title" style="font-size: 18px;">Минус-фразы по артикулам</div>
            <div class="muted-small">Каждый блок ниже соответствует артикулу. Пустой список удалит все минус-фразы.</div>
            <div class="grid-2 mt-12">
                <?php foreach ($nmIds as $nmId): ?>
                    <div class="card" style="background: var(--bg-card-soft);">
                        <form method="post">
                            <div class="flex-space">
                                <strong>Артикул <?= htmlspecialchars($nmId) ?></strong>
                                <div>
                                    <input type="hidden" name="action" value="update_minus_phrases">
                                    <input type="hidden" name="campaign_id" value="<?= htmlspecialchars($currentId) ?>">
                                    <input type="hidden" name="nm_id" value="<?= htmlspecialchars($nmId) ?>">
                                    <button class="btn btn-outline" type="submit">Сохранить</button>
                                </div>
                            </div>
                            <textarea name="minus_phrases" rows="6" style="width:100%; margin-top:8px; border-radius:8px; border:1px solid var(--border-soft); background: var(--bg-card); color: var(--text-main); padding:8px;">
<?= htmlspecialchars(implode("\n", $minusMap[$nmId] ?? [])) ?></textarea>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ТОВАРЫ И СТАВКИ -->
        <div class="card mt-16">
            <div class="flex-space">
                <strong>Товары в кампании и ставки</strong>
                <span class="muted">Конкурентная ставка — минимум по API, «Поиск» — наша редактируемая ставка (400–1500)</span>
            </div>

            <?php if (empty($nmSettings)): ?>
                <div class="mt-12 muted">В кампании нет карточек товаров.</div>
            <?php else: ?>
                <div class="mt-12" style="overflow-x:auto;">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Название товара / предмет</th>
                            <th>Артикул WB</th>
                            <th>Конкурентная ставка (min, поиск)</th>
                            <th>Поиск (наша ставка)</th>
                            <th>Действие</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($nmSettings as $nm): ?>
                            <?php
                            $nmId   = (int)($nm['nm_id'] ?? 0);
                            $subj   = $nm['subject']['name'] ?? '—';
                            $bids   = $nm['bids'] ?? [];
                            $ourBidSearch = $bids['search'] ?? null;
                            $minBidSearch = $minBids[$nmId]['search'] ?? null;
                            ?>
                            <tr>
                                <td>
                                    <div style="font-size: 13px;">
                                        <?= htmlspecialchars($subj) ?>
                                    </div>
                                    <div class="muted">Название товара можно подтянуть отдельным контент-API (здесь доступен только предмет).</div>
                                </td>
                                <td><?= htmlspecialchars($nmId) ?></td>
                                <td>
                                    <?php if ($minBidSearch !== null): ?>
                                        <?= number_format($minBidSearch, 0, ',', ' ') ?>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="post" class="flex-gap" style="align-items:center;">
                                        <input type="hidden" name="action" value="update_bid">
                                        <input type="hidden" name="campaign_id" value="<?= htmlspecialchars($currentId) ?>">
                                        <input type="hidden" name="nm_id" value="<?= htmlspecialchars($nmId) ?>">
                                        <input type="hidden" name="placement" value="search">
                                        <input
                                            class="input"
                                            type="number"
                                            name="new_bid"
                                            min="400"
                                            max="1500"
                                            step="10"
                                            value="<?= htmlspecialchars($ourBidSearch !== null ? (int)$ourBidSearch : 400) ?>"
                                        >
                                        <button type="submit" class="btn btn-outline" style="padding:4px 10px;">Сохранить</button>
                                    </form>
                                </td>
                                <td>
                                    <?php if ($ourBidSearch !== null): ?>
                                        <span class="muted">Текущая: <?= number_format($ourBidSearch, 0, ',', ' ') ?></span>
                                    <?php else: ?>
                                        <span class="muted">Нет ставки</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- СТАТИСТИКА ПО КАМПАНИИ -->
        <div class="card mt-16">
            <div class="flex-space">
                <strong>Статистика по кампании (последние <?= STATS_DAYS ?> дней)</strong>
                <?php if ($stats): ?>
                    <span class="muted">
                        Период: <?= htmlspecialchars($stats['_beginDate']) ?> — <?= htmlspecialchars($stats['_endDate']) ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if (!$stats): ?>
                <div class="mt-12 muted">
                    Статистика не найдена (либо нет данных за период, либо кампания не в статусах 7/9/11).
                </div>
            <?php else: ?>
                <?php
                $sum       = $stats['sum']        ?? 0;
                $sumPrice  = $stats['sum_price']  ?? 0;
                $views     = $stats['views']      ?? 0;
                $clicks    = $stats['clicks']     ?? 0;
                $atbs      = $stats['atbs']       ?? 0;
                $orders    = $stats['orders']     ?? 0;
                $shks      = $stats['shks']       ?? 0;
                $ctr       = $stats['ctr']        ?? 0;
                $cr        = $stats['cr']         ?? 0;
                $cpc       = $stats['cpc']        ?? 0;
                $canceled  = $stats['canceled']   ?? 0;
                $cpm       = $views > 0 ? ($sum / $views * 1000) : 0;
                $avgPos    = null;
                if (!empty($stats['boosterStats']) && is_array($stats['boosterStats'])) {
                    $totalPos = 0; $cnt = 0;
                    foreach ($stats['boosterStats'] as $bs) {
                        if (isset($bs['avg_position'])) {
                            $totalPos += $bs['avg_position'];
                            $cnt++;
                        }
                    }
                    if ($cnt > 0) $avgPos = $totalPos / $cnt;
                }
                ?>
                <div class="mt-12" style="overflow-x:auto;">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Название товара</th>
                            <th>Средняя позиция</th>
                            <th>Затраты, ₽</th>
                            <th>Заказов на сумму, ₽</th>
                            <th>Показы</th>
                            <th>Клики</th>
                            <th>Добавления в корзину</th>
                            <th>Заказанные товары, шт</th>
                            <th>CTR, %</th>
                            <th>CR, %</th>
                            <th>CPM, ₽</th>
                            <th>CPC, ₽</th>
                            <th>Дата начала</th>
                            <th>Дата окончания</th>
                            <th>Отмены, шт</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr>
                            <td>Все товары кампании</td>
                            <td><?= $avgPos !== null ? number_format($avgPos, 2, ',', ' ') : '—' ?></td>
                            <td><?= number_format($sum, 2, ',', ' ') ?></td>
                            <td><?= number_format($sumPrice, 2, ',', ' ') ?></td>
                            <td><?= number_format($views, 0, ',', ' ') ?></td>
                            <td><?= number_format($clicks, 0, ',', ' ') ?></td>
                            <td><?= number_format($atbs, 0, ',', ' ') ?></td>
                            <td><?= number_format($shks, 0, ',', ' ') ?></td>
                            <td><?= number_format($ctr, 2, ',', ' ') ?></td>
                            <td><?= number_format($cr, 2, ',', ' ') ?></td>
                            <td><?= number_format($cpm, 2, ',', ' ') ?></td>
                            <td><?= number_format($cpc, 2, ',', ' ') ?></td>
                            <td><?= htmlspecialchars($stats['_beginDate']) ?></td>
                            <td><?= htmlspecialchars($stats['_endDate']) ?></td>
                            <td><?= number_format($canceled, 0, ',', ' ') ?></td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php endif; // campaign exists ?>

    <?php else: ?>

        <?php
        $all = get_all_campaigns_alltypes();
        $campaigns = $all['items'];
        $listError = $all['error'];
        ?>

        <div class="card">
            <div class="flex-space">
                <strong>Рекламные кампании всех типов (4–9)</strong>
                <span class="muted">Список берётся из /adv/v0/auction/adverts (тип 9) и /adv/v1/promotion/count (типы 4–8)</span>
            </div>

            <?php if ($listError): ?>
                <div class="flash error mt-12">
                    Ошибка при запросе кампаний: <?= htmlspecialchars($listError) ?>
                </div>
            <?php endif; ?>

            <?php if (empty($campaigns)): ?>
                <div class="mt-12 muted">
                    Не удалось получить список кампаний или кампаний нет. Проверь токен (категория «Продвижение») и наличие кампаний.
                </div>
            <?php else: ?>
                <div class="mt-12" style="overflow-x:auto;">
                    <table class="campaign-list">
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Название</th>
                            <th>Тип</th>
                            <th>Источник</th>
                            <th>Статус</th>
                            <th>Модель оплаты</th>
                            <th>Тип ставки</th>
                            <th>Зоны показов</th>
                            <th>Создана</th>
                            <th>Обновлена</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($campaigns as $c): ?>
                            <?php
                            $id        = $c['id'];
                            $name      = $c['name'];
                            $status    = $c['status'];
                            $statusTxt = campaign_status_text($status);
                            $payType   = $c['payment_type'] ?? '—';
                            $bidType   = $c['bid_type'] ?? '—';
                            $placements= $c['placements'] ?? ['search' => null, 'recommendations' => null];
                            $created   = $c['timestamps']['created'] ?? '—';
                            $updated   = $c['timestamps']['updated'] ?? '—';
                            $type      = $c['type'];
                            $src       = $c['src'];
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($id) ?></td>
                                <td><?= htmlspecialchars($name) ?></td>
                                <td>
                                    <span class="badge">Тип <?= htmlspecialchars($type) ?></span>
                                </td>
                                <td>
                                    <span class="badge">
                                        <?= $src === 'auction' ? 'Тип 9 (auction)' : 'Старые типы (promo)' ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="chip <?= $status === 9 ? 'success' : ($status === 11 ? 'warning' : '') ?>">
                                        <?= htmlspecialchars($statusTxt) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($payType ?? '—') ?></td>
                                <td><?= htmlspecialchars($bidType ?? '—') ?></td>
                                <td>
                                    <?php if ($src === 'auction'): ?>
                                        <span class="badge">
                                            Поиск: <?= !empty($placements['search']) ? 'вкл' : 'выкл' ?>
                                        </span>
                                        &nbsp;
                                        <span class="badge">
                                            Рекомендации: <?= !empty($placements['recommendations']) ? 'вкл' : 'выкл' ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="muted">н/д</span>
                                    <?php endif; ?>
                                </td>
                                <td class="muted"><?= htmlspecialchars($created) ?></td>
                                <td class="muted"><?= htmlspecialchars($updated) ?></td>
                                <td>
                                    <?php if ($src === 'auction'): ?>
                                        <a href="?view=campaign&id=<?= htmlspecialchars($id) ?>" class="btn btn-outline">Открыть</a>
                                    <?php else: ?>
                                        <button class="btn btn-outline" disabled>Только просмотр</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <?php endif; ?>

    <div class="mt-16 muted">
        Поднятие сервера: <code>php -S localhost:8080</code> из папки проекта.
        Если вдруг начнут сыпаться 401 — сначала проверь, что токен для категории <strong>Продвижение</strong> верный и не истёк.
    </div>

</div>

<script>
    function syncPlacementsAndSubmit() {
        const form = document.getElementById('placements-form');
        if (!form) return;
        const search = document.getElementById('placement-search');
        const rec = document.getElementById('placement-recommendations');
        document.getElementById('placements-search-state').value = search && search.checked ? 1 : 0;
        document.getElementById('placements-rec-state').value = rec && rec.checked ? 1 : 0;
        form.submit();
    }

    const clusterFilter = document.getElementById('cluster-filter');
    if (clusterFilter) {
        clusterFilter.addEventListener('input', () => {
            const term = clusterFilter.value.trim().toLowerCase();
            document.querySelectorAll('#clusters-table tbody tr').forEach(row => {
                const text = row.getAttribute('data-cluster') || '';
                row.style.display = text.includes(term) ? '' : 'none';
            });
        });
    }
</script>
</body>
</html>
