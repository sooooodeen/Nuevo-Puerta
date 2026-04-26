<?php
  // Suppress all PHP errors and warnings from being output to the browser
  error_reporting(0);
  ini_set('display_errors', 0);
  ob_start(); // Buffer all output so PHP warnings never corrupt AJAX/JSON responses
  session_start();

  // Show and clear error message from session
  $error_message = null;
  if (isset($_SESSION['error_message'])) {
      $error_message = $_SESSION['error_message'];
      unset($_SESSION['error_message']);
  }
  
  // Database connection settings
  $servername = "localhost";
  $username = "root";
  $password = "";
  $dbname = "nuevopuerta";

  $conn = new mysqli($servername, $username, $password, $dbname);
  if ($conn->connect_error) {
      die("Connection Failed: " . $conn->connect_error);
  }
  $conn->set_charset('utf8mb4');
  require_once __DIR__ . '/includes/email_branding.php';

    function tableExists($conn, $tableName): bool {
      $tableName = trim((string)$tableName);
      if ($tableName === '') {
        return false;
      }

      $escapedTable = mysqli_real_escape_string($conn, $tableName);
      $result = mysqli_query($conn, "SHOW TABLES LIKE '{$escapedTable}'");
      return $result instanceof mysqli_result && mysqli_num_rows($result) > 0;
    }

    function columnExists($conn, $tableName, $columnName): bool {
      $tableName = trim((string)$tableName);
      $columnName = trim((string)$columnName);
      if ($tableName === '' || $columnName === '') {
        return false;
      }

      if (!tableExists($conn, $tableName)) {
        return false;
      }

      $escapedTable = mysqli_real_escape_string($conn, $tableName);
      $escapedColumn = mysqli_real_escape_string($conn, $columnName);
      $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$escapedTable}` LIKE '{$escapedColumn}'");
      return $result instanceof mysqli_result && mysqli_num_rows($result) > 0;
    }

    function generateUniqueUserAccountNumber($conn): string {
      // Keep trying until a non-used account number is found.
      for ($attempt = 0; $attempt < 12; $attempt++) {
        $candidate = 'USR-' . date('YmdHis') . '-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        $check = $conn->prepare("SELECT id FROM user_accounts WHERE account_number = ? LIMIT 1");
        if (!$check) {
          break;
        }

        $check->bind_param('s', $candidate);
        $check->execute();
        $res = $check->get_result();
        $exists = $res && $res->fetch_assoc();
        $check->close();

        if (!$exists) {
          return $candidate;
        }
      }

      // Fallback with microtime to avoid collisions if attempts are exhausted.
      return 'USR-' . str_replace('.', '', (string)microtime(true));
    }

    function normalizeLotStatus($status) {
      $status = trim((string)$status);
      if ($status === 'Installments') {
        return 'Installment';
      }
      if ($status === 'Sold') {
        return 'Paid';
      }
      if ($status === '') {
        return 'Available';
      }
      return $status;
    }

    function deriveLotWorkflowStage($status, $paymentType) {
      $normalizedStatus = normalizeLotStatus($status);
      $paymentType = trim((string)$paymentType);

      if ($normalizedStatus === 'Available') {
        return 'Available';
      }

      if ($normalizedStatus === 'Paid') {
        return 'Paid';
      }

      if ($normalizedStatus === 'Installment') {
        return 'Installments';
      }

      if ($normalizedStatus === 'Reserved' && $paymentType === 'Down Payment') {
        return 'Installments';
      }

      if ($normalizedStatus === 'Reserved') {
        return 'Reserved';
      }

      return $normalizedStatus;
    }

    function calculateNextMonthlyDueDate(int $dueDay, ?string $referenceDate = null): ?string {
      if ($dueDay < 1 || $dueDay > 31) {
        return null;
      }

      try {
        $base = $referenceDate ? new DateTime($referenceDate) : new DateTime('today');
      } catch (Exception $e) {
        $base = new DateTime('today');
      }

      $candidate = new DateTime($base->format('Y-m-01'));
      $daysInMonth = (int)$candidate->format('t');
      $candidate->setDate((int)$candidate->format('Y'), (int)$candidate->format('m'), min($dueDay, $daysInMonth));

      if ($candidate < $base) {
        $candidate->modify('first day of next month');
        $daysInMonth = (int)$candidate->format('t');
        $candidate->setDate((int)$candidate->format('Y'), (int)$candidate->format('m'), min($dueDay, $daysInMonth));
      }

      return $candidate->format('Y-m-d');
    }

    function registerJsonFatalHandler() {
      register_shutdown_function(function () {
        $error = error_get_last();
        if (!$error) {
          return;
        }

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array($error['type'], $fatalTypes, true)) {
          return;
        }

        if (ob_get_length()) {
          ob_end_clean();
        }
        if (!headers_sent()) {
          header('Content-Type: application/json');
        }
        echo json_encode([
          'success' => false,
          'error' => 'Fatal PHP error: ' . ($error['message'] ?? 'Unknown error')
        ]);
      });
    }

    function parseSaleLotContext(?string $property, $lotNoRaw = null): array {
      $property = trim((string)$property);
      $lotNoRaw = trim((string)$lotNoRaw);

      $lotNumber = null;
      $blockNumber = null;

      if ($property !== '') {
        if (preg_match('/lot\s*(\d+)\s*[-,]?\s*block\s*(\d+)/i', $property, $m)) {
          $lotNumber = (int)$m[1];
          $blockNumber = (int)$m[2];
        } elseif (preg_match('/block\s*(\d+)\s*[-,]?\s*lot\s*(\d+)/i', $property, $m)) {
          $blockNumber = (int)$m[1];
          $lotNumber = (int)$m[2];
        }
      }

      if ($lotNumber === null && $lotNoRaw !== '' && preg_match('/\d+/', $lotNoRaw, $m)) {
        $lotNumber = (int)$m[0];
      }

      return [
        'lot_number' => $lotNumber,
        'block_number' => $blockNumber,
        'lot_no_text' => $lotNoRaw
      ];
    }

    function resolveFallbackAgentIdForSale($conn, ?string $property, $lotNoRaw, array &$cache): ?int {
      $ctx = parseSaleLotContext($property, $lotNoRaw);
      $lotNumber = $ctx['lot_number'];
      $blockNumber = $ctx['block_number'];
      $lotNoText = $ctx['lot_no_text'];

      $cacheKey = ($blockNumber ?? 'x') . ':' . ($lotNumber ?? 'x') . ':' . ($lotNoText !== '' ? $lotNoText : 'x');
      if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
      }

      $resolvedAgentId = null;
      $lotId = null;

      $hasLotsTable = tableExists($conn, 'lots');
      $hasLotsBlock = $hasLotsTable && columnExists($conn, 'lots', 'block_number');
      $hasLotsLotNo = $hasLotsTable && columnExists($conn, 'lots', 'lot_number');
      $hasLotsAgent = $hasLotsTable && columnExists($conn, 'lots', 'agent_id');
      $hasViewingsTable = tableExists($conn, 'viewings');
      $hasViewingsLotId = $hasViewingsTable && columnExists($conn, 'viewings', 'lot_id');
      $hasViewingsLotNo = $hasViewingsTable && columnExists($conn, 'viewings', 'lot_no');
      $hasViewingsAgent = $hasViewingsTable && columnExists($conn, 'viewings', 'agent_id');

      if ($lotNumber !== null && $blockNumber !== null && $hasLotsBlock && $hasLotsLotNo) {
        $lotStmt = $conn->prepare("SELECT id FROM lots WHERE block_number = ? AND lot_number = ? ORDER BY id DESC LIMIT 1");
        if ($lotStmt) {
          $lotStmt->bind_param('ii', $blockNumber, $lotNumber);
          $lotStmt->execute();
          $lotRes = $lotStmt->get_result();
          if ($lotRes && ($lotRow = $lotRes->fetch_assoc())) {
            $lotId = (int)$lotRow['id'];
          }
          $lotStmt->close();
        }
      }

      if ($lotId !== null && $hasLotsAgent) {
        $lotAgentStmt = $conn->prepare("SELECT agent_id FROM lots WHERE id = ? LIMIT 1");
        if ($lotAgentStmt) {
          $lotAgentStmt->bind_param('i', $lotId);
          $lotAgentStmt->execute();
          $lotAgentRes = $lotAgentStmt->get_result();
          if ($lotAgentRes && ($lotAgentRow = $lotAgentRes->fetch_assoc())) {
            $lotAgentId = (int)($lotAgentRow['agent_id'] ?? 0);
            if ($lotAgentId > 0) {
              $resolvedAgentId = $lotAgentId;
            }
          }
          $lotAgentStmt->close();
        }

      }

      if ($resolvedAgentId === null && $lotId !== null && $hasViewingsLotId && $hasViewingsAgent) {
        $viewingStmt = $conn->prepare("SELECT agent_id FROM viewings WHERE lot_id = ? AND agent_id > 0 ORDER BY id DESC LIMIT 1");
        if ($viewingStmt) {
          $viewingStmt->bind_param('i', $lotId);
          $viewingStmt->execute();
          $viewingRes = $viewingStmt->get_result();
          if ($viewingRes && ($viewingRow = $viewingRes->fetch_assoc())) {
            $resolvedAgentId = (int)$viewingRow['agent_id'];
          }
          $viewingStmt->close();
        }
      }

      if ($resolvedAgentId === null && $lotNoText !== '' && $hasViewingsLotNo && $hasViewingsAgent) {
        $viewingByLotNoStmt = $conn->prepare("SELECT agent_id FROM viewings WHERE lot_no = ? AND agent_id > 0 ORDER BY id DESC LIMIT 1");
        if ($viewingByLotNoStmt) {
          $viewingByLotNoStmt->bind_param('s', $lotNoText);
          $viewingByLotNoStmt->execute();
          $viewingByLotNoRes = $viewingByLotNoStmt->get_result();
          if ($viewingByLotNoRes && ($viewingByLotNoRow = $viewingByLotNoRes->fetch_assoc())) {
            $resolvedAgentId = (int)$viewingByLotNoRow['agent_id'];
          }
          $viewingByLotNoStmt->close();
        }
      }

      if ($resolvedAgentId === null && $lotNumber !== null && $hasViewingsLotNo && $hasViewingsAgent) {
        $lotNumberText = (string)$lotNumber;
        $viewingByParsedLotNoStmt = $conn->prepare("SELECT agent_id FROM viewings WHERE lot_no = ? AND agent_id > 0 ORDER BY id DESC LIMIT 1");
        if ($viewingByParsedLotNoStmt) {
          $viewingByParsedLotNoStmt->bind_param('s', $lotNumberText);
          $viewingByParsedLotNoStmt->execute();
          $viewingByParsedLotNoRes = $viewingByParsedLotNoStmt->get_result();
          if ($viewingByParsedLotNoRes && ($viewingByParsedLotNoRow = $viewingByParsedLotNoRes->fetch_assoc())) {
            $resolvedAgentId = (int)$viewingByParsedLotNoRow['agent_id'];
          }
          $viewingByParsedLotNoStmt->close();
        }
      }

      $cache[$cacheKey] = $resolvedAgentId;
      return $resolvedAgentId;
    }

    function buildTopAgentsLeaderboard($conn, ?string $dateFrom, ?string $dateTo, ?int $locationId, ?string $salesDateCol, ?string $salesLocationCol, ?string $salesAmountExprWithAlias, string $salesScope = 'all', string $rankMode = 'sales'): array {
      $allowedSalesScopes = ['all', 'fully_paid_only', 'not_fully_paid_only'];
      $allowedRankModes = ['sales', 'encouragement'];
      if (!in_array($salesScope, $allowedSalesScopes, true)) {
        $salesScope = 'all';
      }
      if (!in_array($rankMode, $allowedRankModes, true)) {
        $rankMode = 'sales';
      }

      $agents = [];

      $agentRes = mysqli_query($conn, "SELECT id, first_name, last_name, email FROM agent_accounts");
      if ($agentRes) {
        while ($row = mysqli_fetch_assoc($agentRes)) {
          $agentId = (int)$row['id'];
          $agents[$agentId] = [
            'id' => $agentId,
            'name' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
            'email' => (string)($row['email'] ?? ''),
            'sales_count' => 0,
            'total_amount' => 0.0,
            'avg_deal_size' => 0.0,
            'sold_total_amount' => 0.0,
            'sold_lots_count' => 0,
            'reserved_lots_count' => 0,
            'ongoing_lots_count' => 0,
            'cancelled_lots_count' => 0,
            'portfolio_total' => 0,
            'encouraged_not_fully_paid_count' => 0,
            'display_sales_count' => 0,
            'display_total_amount' => 0.0,
            'display_avg_deal_size' => 0.0,
          ];
        }
      }

      $amountExpr = $salesAmountExprWithAlias ? "IFNULL($salesAmountExprWithAlias, 0)" : '0';
      $salesQuery = "SELECT s.agent_id, COUNT(*) AS sales_count, IFNULL(SUM($amountExpr), 0) AS total_amount FROM sales s WHERE s.agent_id IS NOT NULL AND s.agent_id > 0";
      if ($salesDateCol && $dateFrom) {
        $salesQuery .= " AND s.$salesDateCol >= '" . mysqli_real_escape_string($conn, $dateFrom) . " 00:00:00'";
      }
      if ($salesDateCol && $dateTo) {
        $salesQuery .= " AND s.$salesDateCol < DATE_ADD('" . mysqli_real_escape_string($conn, $dateTo) . "', INTERVAL 1 DAY)";
      }
      if ($salesLocationCol && $locationId) {
        $salesQuery .= " AND s.$salesLocationCol = " . (int)$locationId;
      }
      $salesQuery .= " GROUP BY s.agent_id";

      $salesRes = mysqli_query($conn, $salesQuery);
      if ($salesRes) {
        while ($row = mysqli_fetch_assoc($salesRes)) {
          $agentId = (int)($row['agent_id'] ?? 0);
          if ($agentId > 0 && isset($agents[$agentId])) {
            $agents[$agentId]['sales_count'] = (int)($row['sales_count'] ?? 0);
            $agents[$agentId]['total_amount'] = (float)($row['total_amount'] ?? 0);
          }
        }
      }

      // Include sales rows that have no valid agent_id by resolving their likely agent from lot/viewing context.
      $fallbackQuery = "SELECT s.property, s.lot_no, $amountExpr AS sale_amount FROM sales s WHERE (s.agent_id IS NULL OR s.agent_id <= 0)";
      if ($salesDateCol && $dateFrom) {
        $fallbackQuery .= " AND s.$salesDateCol >= '" . mysqli_real_escape_string($conn, $dateFrom) . " 00:00:00'";
      }
      if ($salesDateCol && $dateTo) {
        $fallbackQuery .= " AND s.$salesDateCol < DATE_ADD('" . mysqli_real_escape_string($conn, $dateTo) . "', INTERVAL 1 DAY)";
      }
      if ($salesLocationCol && $locationId) {
        $fallbackQuery .= " AND s.$salesLocationCol = " . (int)$locationId;
      }

      $fallbackCache = [];
      $fallbackRes = mysqli_query($conn, $fallbackQuery);
      if ($fallbackRes) {
        while ($row = mysqli_fetch_assoc($fallbackRes)) {
          $resolvedAgent = resolveFallbackAgentIdForSale($conn, $row['property'] ?? '', $row['lot_no'] ?? '', $fallbackCache);
          $agentId = $resolvedAgent ? (int)$resolvedAgent : 0;
          if ($agentId > 0 && isset($agents[$agentId])) {
            $agents[$agentId]['sales_count'] += 1;
            $agents[$agentId]['total_amount'] += (float)($row['sale_amount'] ?? 0);
          }
        }
      }

      $hasViewingsTable = tableExists($conn, 'viewings');
      $hasViewingsAgent = $hasViewingsTable && columnExists($conn, 'viewings', 'agent_id');
      $hasViewingsStatus = $hasViewingsTable && columnExists($conn, 'viewings', 'status');
      $hasViewingsLotId = $hasViewingsTable && columnExists($conn, 'viewings', 'lot_id');
      $hasViewingsLotNo = $hasViewingsTable && columnExists($conn, 'viewings', 'lot_no');
      $hasViewingsLocation = $hasViewingsTable && columnExists($conn, 'viewings', 'location_id');
      $viewingsDateCol = $hasViewingsTable && columnExists($conn, 'viewings', 'preferred_at')
        ? 'preferred_at'
        : (($hasViewingsTable && columnExists($conn, 'viewings', 'created_at')) ? 'created_at' : null);

      $hasLotsTable = tableExists($conn, 'lots');
      $hasLotsAgent = $hasLotsTable && columnExists($conn, 'lots', 'agent_id');
      $hasLotsOwner = $hasLotsTable && columnExists($conn, 'lots', 'owner_id');
      $hasLotsLotNumber = $hasLotsTable && columnExists($conn, 'lots', 'lot_number');
      $hasLotsLocation = $hasLotsTable && columnExists($conn, 'lots', 'location_id');
      $lotDateCol = $hasLotsTable && columnExists($conn, 'lots', 'updated_at')
        ? 'updated_at'
        : (($hasLotsTable && columnExists($conn, 'lots', 'created_at')) ? 'created_at' : null);

      $hasUsersTable = tableExists($conn, 'user_accounts');
      $hasUsersAgent = $hasUsersTable && columnExists($conn, 'user_accounts', 'agent_id');

      if ($hasLotsTable) {
        $normalizedLotStatusExpr = "CASE
          WHEN l.status = 'Installments' THEN 'Installment'
          WHEN l.status = 'Sold' THEN 'Paid'
          WHEN l.status = '' OR l.status IS NULL THEN 'Available'
          ELSE l.status
        END";

        $lotPortfolioJoins = '';
        $resolvedAgentParts = [];

        if ($hasLotsAgent) {
          $resolvedAgentParts[] = 'NULLIF(l.agent_id, 0)';
        }

        if ($hasLotsOwner && $hasUsersAgent) {
          $lotPortfolioJoins .= " LEFT JOIN user_accounts u ON u.id = l.owner_id";
          $resolvedAgentParts[] = 'NULLIF(u.agent_id, 0)';
        }

        if ($hasViewingsTable && $hasViewingsAgent && $hasViewingsLotId) {
          $lotPortfolioJoins .= " LEFT JOIN (
              SELECT v1.lot_id, v1.agent_id
              FROM viewings v1
              INNER JOIN (
                SELECT lot_id, MAX(id) AS latest_id
                FROM viewings
                WHERE lot_id IS NOT NULL AND lot_id > 0 AND agent_id IS NOT NULL AND agent_id > 0
                GROUP BY lot_id
              ) lv ON lv.latest_id = v1.id
            ) vl ON vl.lot_id = l.id";
          $resolvedAgentParts[] = 'NULLIF(vl.agent_id, 0)';
        }

        if ($hasViewingsTable && $hasViewingsAgent && $hasViewingsLotNo && $hasLotsLotNumber) {
          $lotPortfolioJoins .= " LEFT JOIN (
              SELECT LOWER(TRIM(v2.lot_no)) AS lot_no_key, v2.agent_id
              FROM viewings v2
              INNER JOIN (
                SELECT LOWER(TRIM(lot_no)) AS lot_no_key, MAX(id) AS latest_id
                FROM viewings
                WHERE lot_no IS NOT NULL AND TRIM(lot_no) <> '' AND agent_id IS NOT NULL AND agent_id > 0
                GROUP BY LOWER(TRIM(lot_no))
              ) lv2 ON lv2.latest_id = v2.id
            ) vn ON (
              vn.lot_no_key = LOWER(TRIM(CAST(l.lot_number AS CHAR)))
              OR vn.lot_no_key LIKE CONCAT('%lot ', LOWER(TRIM(CAST(l.lot_number AS CHAR))), '%')
            )";
          $resolvedAgentParts[] = 'NULLIF(vn.agent_id, 0)';
        }

        $resolvedAgentExpr = !empty($resolvedAgentParts)
          ? ('COALESCE(' . implode(', ', $resolvedAgentParts) . ', 0)')
          : '0';

        $lotPortfolioQuery = "SELECT
            {$resolvedAgentExpr} AS resolved_agent_id,
            SUM(CASE WHEN {$normalizedLotStatusExpr} = 'Paid' THEN IFNULL(l.lot_price, 0) ELSE 0 END) AS sold_total_amount,
            SUM(CASE WHEN {$normalizedLotStatusExpr} = 'Paid' THEN 1 ELSE 0 END) AS sold_lots_count,
            SUM(CASE WHEN {$normalizedLotStatusExpr} = 'Reserved' THEN 1 ELSE 0 END) AS reserved_lots_count,
            SUM(CASE WHEN {$normalizedLotStatusExpr} = 'Installment' THEN 1 ELSE 0 END) AS ongoing_lots_count
          FROM lots l
          {$lotPortfolioJoins}
          WHERE {$resolvedAgentExpr} > 0";

        if ($locationId && $hasLotsLocation) {
          $lotPortfolioQuery .= " AND l.location_id = " . (int)$locationId;
        }
        if ($lotDateCol && $dateFrom) {
          $lotPortfolioQuery .= " AND l.$lotDateCol >= '" . mysqli_real_escape_string($conn, $dateFrom) . " 00:00:00'";
        }
        if ($lotDateCol && $dateTo) {
          $lotPortfolioQuery .= " AND l.$lotDateCol < DATE_ADD('" . mysqli_real_escape_string($conn, $dateTo) . "', INTERVAL 1 DAY)";
        }
        $lotPortfolioQuery .= " GROUP BY resolved_agent_id";

        $lotPortfolioRes = mysqli_query($conn, $lotPortfolioQuery);
        if ($lotPortfolioRes) {
          while ($row = mysqli_fetch_assoc($lotPortfolioRes)) {
            $agentId = (int)($row['resolved_agent_id'] ?? 0);
            if ($agentId > 0 && isset($agents[$agentId])) {
              $agents[$agentId]['sold_total_amount'] += (float)($row['sold_total_amount'] ?? 0);
              $agents[$agentId]['sold_lots_count'] += (int)($row['sold_lots_count'] ?? 0);
              $agents[$agentId]['reserved_lots_count'] += (int)($row['reserved_lots_count'] ?? 0);
              $agents[$agentId]['ongoing_lots_count'] += (int)($row['ongoing_lots_count'] ?? 0);
            }
          }
        }
      }

      if ($hasViewingsTable && $hasViewingsAgent && $hasViewingsStatus) {
        $cancelledLotKeyExpr = 'CAST(v.id AS CHAR)';
        if ($hasViewingsLotId && $hasViewingsLotNo) {
          $cancelledLotKeyExpr = "CASE
            WHEN v.lot_id IS NOT NULL AND v.lot_id > 0 THEN CONCAT('lot:', v.lot_id)
            WHEN v.lot_no IS NOT NULL AND TRIM(v.lot_no) <> '' THEN CONCAT('lotno:', LOWER(TRIM(v.lot_no)))
            ELSE CONCAT('viewing:', v.id)
          END";
        } elseif ($hasViewingsLotId) {
          $cancelledLotKeyExpr = "CASE
            WHEN v.lot_id IS NOT NULL AND v.lot_id > 0 THEN CONCAT('lot:', v.lot_id)
            ELSE CONCAT('viewing:', v.id)
          END";
        } elseif ($hasViewingsLotNo) {
          $cancelledLotKeyExpr = "CASE
            WHEN v.lot_no IS NOT NULL AND TRIM(v.lot_no) <> '' THEN CONCAT('lotno:', LOWER(TRIM(v.lot_no)))
            ELSE CONCAT('viewing:', v.id)
          END";
        }

        $cancelledQuery = "SELECT v.agent_id, COUNT(DISTINCT {$cancelledLotKeyExpr}) AS cancelled_lots_count
                           FROM viewings v";

        $joinedLotsForCancel = false;
        if ($locationId && !$hasViewingsLocation && $hasLotsTable && $hasLotsLocation && $hasViewingsLotId) {
          $cancelledQuery .= " LEFT JOIN lots l ON l.id = v.lot_id";
          $joinedLotsForCancel = true;
        }

        $cancelledQuery .= " WHERE v.agent_id IS NOT NULL
                               AND v.agent_id > 0
                               AND LOWER(TRIM(v.status)) IN ('cancelled','canceled')";

        if ($viewingsDateCol && $dateFrom) {
          $cancelledQuery .= " AND v.$viewingsDateCol >= '" . mysqli_real_escape_string($conn, $dateFrom) . " 00:00:00'";
        }
        if ($viewingsDateCol && $dateTo) {
          $cancelledQuery .= " AND v.$viewingsDateCol < DATE_ADD('" . mysqli_real_escape_string($conn, $dateTo) . "', INTERVAL 1 DAY)";
        }
        if ($locationId && $hasViewingsLocation) {
          $cancelledQuery .= " AND v.location_id = " . (int)$locationId;
        } elseif ($locationId && $joinedLotsForCancel) {
          $cancelledQuery .= " AND l.location_id = " . (int)$locationId;
        }

        $cancelledQuery .= " GROUP BY v.agent_id";

        $cancelledRes = mysqli_query($conn, $cancelledQuery);
        if ($cancelledRes) {
          while ($row = mysqli_fetch_assoc($cancelledRes)) {
            $agentId = (int)($row['agent_id'] ?? 0);
            if ($agentId > 0 && isset($agents[$agentId])) {
              $agents[$agentId]['cancelled_lots_count'] = (int)($row['cancelled_lots_count'] ?? 0);
            }
          }
        }
      }

      $ranked = [];
      foreach ($agents as $agent) {
        $agent['encouraged_not_fully_paid_count'] = (int)$agent['reserved_lots_count'] + (int)$agent['ongoing_lots_count'];
        $agent['portfolio_total'] =
          (int)$agent['sold_lots_count']
          + (int)$agent['reserved_lots_count']
          + (int)$agent['ongoing_lots_count']
          + (int)$agent['cancelled_lots_count'];

        if ($salesScope === 'fully_paid_only') {
          $agent['display_sales_count'] = (int)$agent['sold_lots_count'];
          $agent['display_total_amount'] = (float)$agent['sold_total_amount'];
        } elseif ($salesScope === 'not_fully_paid_only') {
          $agent['display_sales_count'] = (int)$agent['encouraged_not_fully_paid_count'];
          $agent['display_total_amount'] = 0.0;
        } else {
          $agent['display_sales_count'] = (int)$agent['sales_count'];
          $agent['display_total_amount'] = (float)$agent['total_amount'];
        }

        if (
          (int)$agent['display_sales_count'] <= 0
          && (int)$agent['portfolio_total'] <= 0
          && (float)$agent['total_amount'] <= 0
        ) {
          continue;
        }
        $agent['display_avg_deal_size'] = $agent['display_sales_count'] > 0
          ? round($agent['display_total_amount'] / $agent['display_sales_count'], 2)
          : 0.0;

        $agent['avg_deal_size'] = $agent['display_avg_deal_size'];
        $agent['sales_count'] = $agent['display_sales_count'];
        $agent['total_amount'] = $agent['display_total_amount'];
        $ranked[] = $agent;
      }

      if ($rankMode === 'encouragement') {
        usort($ranked, function ($a, $b) {
          $encouragementCmp = ((int)$b['encouraged_not_fully_paid_count']) <=> ((int)$a['encouraged_not_fully_paid_count']);
          if ($encouragementCmp !== 0) return $encouragementCmp;
          $ongoingCmp = ((int)$b['ongoing_lots_count']) <=> ((int)$a['ongoing_lots_count']);
          if ($ongoingCmp !== 0) return $ongoingCmp;
          $reservedCmp = ((int)$b['reserved_lots_count']) <=> ((int)$a['reserved_lots_count']);
          if ($reservedCmp !== 0) return $reservedCmp;
          $soldCmp = ((int)$b['sold_lots_count']) <=> ((int)$a['sold_lots_count']);
          if ($soldCmp !== 0) return $soldCmp;
          return strcmp((string)$a['name'], (string)$b['name']);
        });
      } else {
        usort($ranked, function ($a, $b) {
          $countCmp = ((int)$b['sales_count']) <=> ((int)$a['sales_count']);
          if ($countCmp !== 0) return $countCmp;
          $soldCmp = ((int)$b['sold_lots_count']) <=> ((int)$a['sold_lots_count']);
          if ($soldCmp !== 0) return $soldCmp;
          $portfolioCmp = ((int)$b['portfolio_total']) <=> ((int)$a['portfolio_total']);
          if ($portfolioCmp !== 0) return $portfolioCmp;
          $amountCmp = ((float)$b['total_amount']) <=> ((float)$a['total_amount']);
          if ($amountCmp !== 0) return $amountCmp;
          return strcmp((string)$a['name'], (string)$b['name']);
        });
      }

      return array_slice($ranked, 0, 10);
    }

    function insertRecipientNotification($conn, $recipientType, $recipientId, $title, $message, $type = 'info') {
      if (!tableExists($conn, 'notifications')) {
        return false;
      }

      $candidateColumns = ['recipient_type', 'recipient_id', 'title', 'message', 'type', 'is_read', 'created_at'];
      $columns = [];
      foreach ($candidateColumns as $col) {
        if (columnExists($conn, 'notifications', $col)) {
          $columns[] = $col;
        }
      }

      // Minimum viable notification fields.
      if (!in_array('title', $columns, true) || !in_array('message', $columns, true)) {
        return false;
      }

      $recipientTypeEsc = mysqli_real_escape_string($conn, (string)$recipientType);
      $titleEsc = mysqli_real_escape_string($conn, (string)$title);
      $messageEsc = mysqli_real_escape_string($conn, (string)$message);
      $typeEsc = mysqli_real_escape_string($conn, (string)$type);
      $recipientIdInt = (int)$recipientId;

      $valuesSql = [];
      foreach ($columns as $col) {
        if ($col === 'recipient_type') {
          $valuesSql[] = "'$recipientTypeEsc'";
        } elseif ($col === 'recipient_id') {
          $valuesSql[] = (string)$recipientIdInt;
        } elseif ($col === 'title') {
          $valuesSql[] = "'$titleEsc'";
        } elseif ($col === 'message') {
          $valuesSql[] = "'$messageEsc'";
        } elseif ($col === 'type') {
          $valuesSql[] = "'$typeEsc'";
        } elseif ($col === 'is_read') {
          $valuesSql[] = '0';
        } elseif ($col === 'created_at') {
          $valuesSql[] = 'NOW()';
        }
      }

      $sql = "INSERT INTO notifications (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $valuesSql) . ")";
      return (bool)mysqli_query($conn, $sql);
    }

    function insertUserNotificationFeed($conn, $userId, $title, $message, $type = 'info') {
      $userId = (int)$userId;
      if ($userId <= 0) return false;

      $createSql = "CREATE TABLE IF NOT EXISTS user_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(180) NOT NULL,
        message TEXT NOT NULL,
        type VARCHAR(30) DEFAULT 'info',
        is_read TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_notifications_user (user_id, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
      mysqli_query($conn, $createSql);

      $titleEsc = mysqli_real_escape_string($conn, (string)$title);
      $messageEsc = mysqli_real_escape_string($conn, (string)$message);
      $typeEsc = mysqli_real_escape_string($conn, (string)$type);

      $insertSql = "INSERT INTO user_notifications (user_id, title, message, type, is_read, created_at)
                    VALUES ($userId, '$titleEsc', '$messageEsc', '$typeEsc', 0, NOW())";
      return (bool)mysqli_query($conn, $insertSql);
    }

    function sendSmsViaGateway($phoneRaw, $message, &$errorMessage = '') {
      $apiUrl = trim((string)getenv('SMS_API_URL'));
      $apiKey = trim((string)getenv('SMS_API_KEY'));
      $sender = trim((string)getenv('SMS_SENDER'));

      if ($apiUrl === '') {
        $errorMessage = 'SMS_API_URL not configured';
        return false;
      }

      $phone = preg_replace('/\s+/', '', (string)$phoneRaw);
      if ($phone === '') {
        $errorMessage = 'Missing recipient mobile number';
        return false;
      }

      $payload = json_encode([
        'to' => $phone,
        'message' => (string)$message,
        'sender' => $sender !== '' ? $sender : 'Nuevo Puerta'
      ]);

      if ($payload === false) {
        $errorMessage = 'Failed to encode SMS payload';
        return false;
      }

      $headers = [
        'Content-Type: application/json',
        'Accept: application/json'
      ];
      if ($apiKey !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
      }

      if (function_exists('curl_init')) {
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $resp = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr = curl_error($ch);
        $ch = null;

        if ($resp === false || $httpCode < 200 || $httpCode >= 300) {
          $errorMessage = $curlErr !== '' ? $curlErr : ('SMS gateway HTTP ' . $httpCode);
          return false;
        }
        return true;
      }

      $errorMessage = 'cURL extension is not enabled';
      return false;
    }

    function shouldUseSmtpForSystemMail(): bool {
      $cfg = getSystemMailConfig();
      return $cfg['host'] !== '' && $cfg['user'] !== '' && $cfg['pass'] !== '';
    }

    function getSystemMailConfig(): array {
      $systemUser = trim((string)(getenv('SYSTEM_SMTP_USER') ?: ''));
      $systemPass = trim((string)(getenv('SYSTEM_SMTP_PASS') ?: ''));

      $legacyHost = (string)(getenv('SMTP_HOST') ?: 'smtp.gmail.com');
      $legacyUser = trim((string)(getenv('SMTP_USER') ?: 'carlomallari01471@gmail.com'));
      $legacyPass = trim((string)(getenv('SMTP_PASS') ?: 'rsmv pipf ijxf phha'));

      $useSystemCredentials = ($systemUser !== '' && $systemPass !== '');
      $host = (string)(getenv('SYSTEM_SMTP_HOST') ?: $legacyHost);
      $user = $useSystemCredentials ? $systemUser : $legacyUser;
      $pass = $useSystemCredentials ? $systemPass : $legacyPass;
      $port = (int)(getenv('SYSTEM_SMTP_PORT') ?: getenv('SMTP_PORT') ?: 587);
      $secure = (string)(getenv('SYSTEM_SMTP_SECURE') ?: getenv('SMTP_SECURE') ?: 'tls');
      $fromEmail = (string)(getenv('SYSTEM_SMTP_FROM_EMAIL') ?: ($useSystemCredentials ? $systemUser : $user));
      $fromName = (string)(getenv('SYSTEM_SMTP_FROM_NAME') ?: 'Nuevo Puerta Real Estate');

      return [
        'host' => $host,
        'user' => $user,
        'pass' => $pass,
        'port' => $port,
        'secure' => $secure,
        'from_email' => $fromEmail,
        'from_name' => $fromName,
      ];
    }

    function ensureSystemMailerLoaded(): bool {
      if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        return true;
      }

      $candidatePaths = [
        __DIR__ . '/vendor/phpmailer/src/PHPMailer.php',
        __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php'
      ];

      $basePath = null;
      foreach ($candidatePaths as $path) {
        if (file_exists($path)) {
          $basePath = dirname($path);
          break;
        }
      }

      if ($basePath === null) {
        return false;
      }

      require_once $basePath . '/PHPMailer.php';
      require_once $basePath . '/SMTP.php';
      require_once $basePath . '/Exception.php';

      return class_exists('PHPMailer\\PHPMailer\\PHPMailer');
    }

    function resolveSystemEmailLogoPath(): ?string {
      $candidates = [
        __DIR__ . '/assets/f.png',
        __DIR__ . '/assets/logo.png',
        __DIR__ . '/logo.png',
        __DIR__ . '/Login/img/Logo.png',
      ];

      foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
          return $candidate;
        }
      }

      return null;
    }

    function buildSystemEmailHtml(string $bodyText, ?string $logoSrc = null): string {
      return buildNovoPuertaEmailHtml(
        'Nuevo Puerta Notification',
        nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8')),
        [
          'intro' => 'This is an automated message from Nuevo Puerta Real Estate.',
          'footer_note' => 'Please keep this email for your records.',
        ]
      );
    }

    function sendSystemEmail($to, $toName, $subject, $body, &$errorMessage = '', array $attachments = []): bool {
      $errorMessage = '';
      $to = trim((string)$to);
      $toName = trim((string)$toName);
      $logoPath = resolveSystemEmailLogoPath();

      $normalizedAttachments = [];
      foreach ($attachments as $attachment) {
        if (!is_array($attachment)) {
          continue;
        }
        $path = trim((string)($attachment['path'] ?? ''));
        if ($path === '' || !is_file($path)) {
          continue;
        }
        $name = trim((string)($attachment['name'] ?? ''));
        if ($name === '') {
          $name = basename($path);
        }
        $normalizedAttachments[] = ['path' => $path, 'name' => $name];
      }

      if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'Invalid recipient email';
        return false;
      }

      if (shouldUseSmtpForSystemMail()) {
        if (!ensureSystemMailerLoaded()) {
          $errorMessage = 'PHPMailer files not found';
          return false;
        }

        $cfg = getSystemMailConfig();
        for ($attempt = 1; $attempt <= 2; $attempt++) {
          try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $cfg['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $cfg['user'];
            $mail->Password = $cfg['pass'];
            $mail->Port = (int)$cfg['port'];
            $mail->SMTPSecure = $cfg['secure'];

            $mail->setFrom($cfg['from_email'], $cfg['from_name']);
            $mail->addAddress($to, $toName !== '' ? $toName : 'Client');
            $logoSrc = null;
            if ($logoPath !== null) {
              $mail->addEmbeddedImage($logoPath, 'nuevo_puerta_logo', basename($logoPath));
              $logoSrc = 'cid:nuevo_puerta_logo';
            }
            foreach ($normalizedAttachments as $attachment) {
              $mail->addAttachment($attachment['path'], $attachment['name']);
            }

            $mail->isHTML(true);
            $mail->Subject = (string)$subject;
            $mail->Body = buildSystemEmailHtml((string)$body, $logoSrc);
            $mail->AltBody = (string)$body . "\n\nCopyright (c) " . date('Y') . " Nuevo Puerta Real Estate. All rights reserved.";
            $mail->send();
            return true;
          } catch (Throwable $e) {
            $errorMessage = $e->getMessage();
          }
        }
        return false;
      }

      if (!empty($normalizedAttachments) && ensureSystemMailerLoaded()) {
        try {
          $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
          $mail->setFrom('no-reply@nuevopuerta.local', 'Nuevo Puerta');
          $mail->addAddress($to, $toName !== '' ? $toName : 'Client');
          $logoSrc = null;
          if ($logoPath !== null) {
            $mail->addEmbeddedImage($logoPath, 'nuevo_puerta_logo', basename($logoPath));
            $logoSrc = 'cid:nuevo_puerta_logo';
          }
          foreach ($normalizedAttachments as $attachment) {
            $mail->addAttachment($attachment['path'], $attachment['name']);
          }
          $mail->isHTML(true);
          $mail->Subject = (string)$subject;
          $mail->Body = buildSystemEmailHtml((string)$body, $logoSrc);
          $mail->AltBody = (string)$body . "\n\nCopyright (c) " . date('Y') . " Nuevo Puerta Real Estate. All rights reserved.";
          $mail->send();
          return true;
        } catch (Throwable $e) {
          $errorMessage = $e->getMessage();
          return false;
        }
      }

      $headers = "From: Nuevo Puerta <no-reply@nuevopuerta.local>\r\n";
      $headers .= "Reply-To: no-reply@nuevopuerta.local\r\n";
      $headers .= "MIME-Version: 1.0\r\n";
      $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
      $headers .= 'X-Mailer: PHP/' . phpversion();

      $inlineLogoSrc = null;
      if ($logoPath !== null) {
        $logoData = @file_get_contents($logoPath);
        if ($logoData !== false) {
          $ext = strtolower((string)pathinfo($logoPath, PATHINFO_EXTENSION));
          $mime = $ext === 'png' ? 'image/png' : (($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : 'application/octet-stream');
          $inlineLogoSrc = 'data:' . $mime . ';base64,' . base64_encode($logoData);
        }
      }

      $htmlBody = buildSystemEmailHtml((string)$body, $inlineLogoSrc);

      $sent = mail($to, (string)$subject, $htmlBody, $headers);
      if (!$sent) {
        $errorMessage = 'mail() failed. Configure SMTP_* environment variables for reliable delivery.';
      }
      return $sent;
    }

    $salesHasAmountCol = columnExists($conn, 'sales', 'amount');
    $salesHasSalePriceCol = columnExists($conn, 'sales', 'sale_price');
    $salesAmountCol = $salesHasAmountCol
      ? 'amount'
      : ($salesHasSalePriceCol ? 'sale_price' : null);
    $salesAmountExprRoot = $salesHasAmountCol && $salesHasSalePriceCol
      ? 'CASE WHEN amount IS NOT NULL AND amount > 0 THEN amount WHEN sale_price IS NOT NULL AND sale_price > 0 THEN sale_price ELSE COALESCE(amount, sale_price, 0) END'
      : ($salesHasAmountCol
          ? 'COALESCE(amount, 0)'
          : ($salesHasSalePriceCol ? 'COALESCE(sale_price, 0)' : null));
    $salesAmountExprWithAlias = $salesHasAmountCol && $salesHasSalePriceCol
      ? 'CASE WHEN s.amount IS NOT NULL AND s.amount > 0 THEN s.amount WHEN s.sale_price IS NOT NULL AND s.sale_price > 0 THEN s.sale_price ELSE COALESCE(s.amount, s.sale_price, 0) END'
      : ($salesHasAmountCol
          ? 'COALESCE(s.amount, 0)'
          : ($salesHasSalePriceCol ? 'COALESCE(s.sale_price, 0)' : null));
    $salesDateCol = columnExists($conn, 'sales', 'sale_date')
      ? 'sale_date'
      : (columnExists($conn, 'sales', 'created_at') ? 'created_at' : null);
    $salesLocationCol = columnExists($conn, 'sales', 'location_id') ? 'location_id' : null;

  // =============================================
  // CREATE PIN LOCATIONS TABLE IF NOT EXISTS
  // =============================================
  $createTableSQL = "CREATE TABLE IF NOT EXISTS pin_locations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    lot_id INT NOT NULL UNIQUE,
    polygon_coordinates LONGTEXT,
    pin_status VARCHAR(50) DEFAULT 'Available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (lot_id) REFERENCES lots(id) ON DELETE CASCADE
  )";
  mysqli_query($conn, $createTableSQL);
  
  // Add pin_status column to existing tables
  mysqli_query($conn, "ALTER TABLE pin_locations ADD COLUMN IF NOT EXISTS pin_status VARCHAR(50) DEFAULT 'Available'");
  // Add payment columns to lots table for Manage Lots payment tracking
  mysqli_query($conn, "ALTER TABLE lots ADD COLUMN IF NOT EXISTS payment_type VARCHAR(30) DEFAULT 'Fully Paid'");
  mysqli_query($conn, "ALTER TABLE lots ADD COLUMN IF NOT EXISTS payment_amount DECIMAL(12,2) DEFAULT NULL");
  mysqli_query($conn, "ALTER TABLE lots ADD COLUMN IF NOT EXISTS down_payment_amount DECIMAL(12,2) DEFAULT NULL");
  mysqli_query($conn, "ALTER TABLE lots ADD COLUMN IF NOT EXISTS commission_amount DECIMAL(12,2) DEFAULT NULL");
  mysqli_query($conn, "ALTER TABLE lots ADD COLUMN IF NOT EXISTS payment_deadline DATE DEFAULT NULL");
  mysqli_query($conn, "ALTER TABLE lots ADD COLUMN IF NOT EXISTS payment_term_years INT DEFAULT NULL");
  mysqli_query($conn, "ALTER TABLE lots ADD COLUMN IF NOT EXISTS payment_due_day TINYINT DEFAULT NULL");

  // Payment ledger for installment history and due tracking.
  mysqli_query($conn, "CREATE TABLE IF NOT EXISTS lot_payment_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lot_id INT NOT NULL,
    user_id INT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method VARCHAR(40) DEFAULT 'Cash',
    remarks VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_lot_payment_lot (lot_id),
    INDEX idx_lot_payment_user (user_id),
    CONSTRAINT fk_lot_payment_lot FOREIGN KEY (lot_id) REFERENCES lots(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // Turnover tracking once fully paid.
  mysqli_query($conn, "CREATE TABLE IF NOT EXISTS lot_turnovers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lot_id INT NOT NULL UNIQUE,
    turnover_date DATE NOT NULL,
    title_released TINYINT(1) DEFAULT 0,
    is_confirmed TINYINT(1) DEFAULT 0,
    remarks VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_lot_turnover_lot FOREIGN KEY (lot_id) REFERENCES lots(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  mysqli_query($conn, "ALTER TABLE lot_turnovers ADD COLUMN IF NOT EXISTS is_confirmed TINYINT(1) DEFAULT 0");

  // =============================================
  // FETCH SINGLE USER (AJAX: ?fetch=user&id=..)
  // =============================================
  if ($_SERVER['REQUEST_METHOD'] === 'GET' &&
      isset($_GET['fetch']) && $_GET['fetch'] === 'user' &&
      isset($_GET['id'])) {

      $user_id = intval($_GET['id']);
      $userQuery = "SELECT * FROM user_accounts WHERE id = $user_id LIMIT 1";
      $userResult = mysqli_query($conn, $userQuery);
      $user = $userResult ? mysqli_fetch_assoc($userResult) : null;

      header('Content-Type: application/json');
      echo json_encode($user);
      exit;
  }

  // =============================================
  // LOOKUP USER BY CONTACT (AJAX: ?fetch=lookup_client&email=..&phone=..)
  // =============================================
  if ($_SERVER['REQUEST_METHOD'] === 'GET' &&
      isset($_GET['fetch']) && $_GET['fetch'] === 'lookup_client') {

      $emailRaw = trim((string)($_GET['email'] ?? ''));
      $phoneRaw = trim((string)($_GET['phone'] ?? ''));

      $email = strtolower($emailRaw);
      $phoneDigits = preg_replace('/\D+/', '', $phoneRaw);

      $userPhoneColumn = columnExists($conn, 'user_accounts', 'phone_number')
        ? 'phone_number'
        : (columnExists($conn, 'user_accounts', 'mobile_number') ? 'mobile_number' : null);

      $whereParts = [];
      if ($email !== '') {
        $whereParts[] = "LOWER(TRIM(email)) = '" . mysqli_real_escape_string($conn, $email) . "'";
      }

      if ($userPhoneColumn !== null && $phoneDigits !== '') {
        $phoneExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($userPhoneColumn, ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), '.', ''), '/', '')";
        $whereParts[] = "$phoneExpr = '" . mysqli_real_escape_string($conn, $phoneDigits) . "'";
      }

      header('Content-Type: application/json');
      if (empty($whereParts)) {
        echo json_encode(['success' => true, 'account' => null]);
        exit;
      }

      $phoneSelectAlias = $userPhoneColumn !== null ? ("$userPhoneColumn AS mobile_number") : "'' AS mobile_number";
      $query = "SELECT id, first_name, middle_name, last_name, username, email, $phoneSelectAlias, address, created_at FROM user_accounts WHERE " . implode(' OR ', $whereParts) . " ORDER BY id DESC LIMIT 1";
      $result = mysqli_query($conn, $query);
      $account = $result ? mysqli_fetch_assoc($result) : null;

      echo json_encode(['success' => true, 'account' => $account]);
      exit;
  }

  // =============================================
  // ADMIN INFO FROM SESSION
  // =============================================
  $admin_id   = $_SESSION['admin_id'] ?? null;
  $admin_name = "Admin";
  $admin_role = "Super Admin";

  if ($admin_id) {
      $result = mysqli_query($conn, "SELECT first_name, last_name FROM admin_accounts WHERE id = $admin_id LIMIT 1");
      if ($row = mysqli_fetch_assoc($result)) {
          $admin_name = $row['first_name'] . ' ' . $row['last_name'];
      }
  }

  // Count unread lot status records for admin
  $unreadLotStatusCount = 0;
  $historyCheck = $conn->query("SHOW TABLES LIKE 'lot_status_history'");
  if ($historyCheck && $historyCheck->num_rows > 0) {
    // Ensure is_read column exists
    $hasIsReadCol = $conn->query("SHOW COLUMNS FROM lot_status_history LIKE 'is_read'");
    if (!$hasIsReadCol || $hasIsReadCol->num_rows === 0) {
      $conn->query("ALTER TABLE lot_status_history ADD COLUMN is_read TINYINT(1) DEFAULT 0");
    }
    
    $countSql = "SELECT COUNT(*) as c FROM lot_status_history WHERE event_type = 'surrender' AND is_read = 0";
    $countRes = $conn->query($countSql);
    if ($countRes) {
      $countRow = $countRes->fetch_assoc();
      $unreadLotStatusCount = (int)($countRow['c'] ?? 0);
    }
  }

  // Count unread notifications for admin
  $unreadNotificationsCount = 0;
  $notifCheck = $conn->query("SHOW TABLES LIKE 'notifications'");
  if ($notifCheck && $notifCheck->num_rows > 0) {
    // Ensure is_read column exists
    $hasIsReadColNotif = $conn->query("SHOW COLUMNS FROM notifications LIKE 'is_read'");
    if (!$hasIsReadColNotif || $hasIsReadColNotif->num_rows === 0) {
      $conn->query("ALTER TABLE notifications ADD COLUMN is_read TINYINT(1) DEFAULT 0");
    }
    
    $countSqlNotif = "SELECT COUNT(*) as c FROM notifications WHERE is_read = 0";
    $countResNotif = $conn->query($countSqlNotif);
    if ($countResNotif) {
      $countRowNotif = $countResNotif->fetch_assoc();
      $unreadNotificationsCount = (int)($countRowNotif['c'] ?? 0);
    }
  }

  // =============================================
  // DASHBOARD STATS (CLIENTS / LOTS / AGENTS)
  // =============================================
  $dashboard_stats = [
      'clients' => 0,
      'lots'    => 0,
      'agents'  => 0
  ];

  // total clients
  $clientQuery  = "SELECT COUNT(*) as total FROM user_accounts";
  $clientResult = mysqli_query($conn, $clientQuery);
  if ($clientResult) {
      $clientRow = mysqli_fetch_assoc($clientResult);
      $dashboard_stats['clients'] = (int)$clientRow['total'];
  }

  // total lots
  $lotsQuery  = "SELECT COUNT(*) as total FROM lots";
  $lotsResult = mysqli_query($conn, $lotsQuery);
  if ($lotsResult) {
      $lotsRow = mysqli_fetch_assoc($lotsResult);
      $dashboard_stats['lots'] = (int)$lotsRow['total'];
  }

  // total active agents
  $agentsQuery  = "SELECT COUNT(*) as total FROM agent_accounts WHERE status = 'active' AND availability = 1";
  $agentsResult = mysqli_query($conn, $agentsQuery);
  if ($agentsResult) {
      $agentsRow = mysqli_fetch_assoc($agentsResult);
      $dashboard_stats['agents'] = (int)$agentsRow['total'];
  }

  // =============================================
  // TOP AGENTS (AJAX: ?fetch=top_agents)
  // =============================================
  if ($_SERVER['REQUEST_METHOD'] === 'GET' &&
      isset($_GET['fetch']) && $_GET['fetch'] === 'top_agents') {

      $date_from   = normalizeAnalyticsDate($_GET['date_from'] ?? null);
      $date_to     = normalizeAnalyticsDate($_GET['date_to'] ?? null);
      $location_id = isset($_GET['location_id']) ? intval($_GET['location_id']) : null;
      $sales_scope = trim((string)($_GET['sales_scope'] ?? 'all'));
      $rank_mode = trim((string)($_GET['rank_mode'] ?? 'sales'));

      if (($date_from === null && !empty($_GET['date_from'])) || ($date_to === null && !empty($_GET['date_to']))) {
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
      }

      header('Content-Type: application/json');
      try {
        $agents = buildTopAgentsLeaderboard(
          $conn,
          $date_from,
          $date_to,
          $location_id,
          $salesDateCol,
          $salesLocationCol,
          $salesAmountExprWithAlias,
          $sales_scope,
          $rank_mode
        );
        echo json_encode($agents);
      } catch (Throwable $e) {
        echo json_encode([]);
      }
      exit;
  }

  // =============================================
  // ALL PAYMENTS (AJAX: ?fetch=all_payments)
  // =============================================
  if ($_SERVER['REQUEST_METHOD'] === 'GET' &&
      isset($_GET['fetch']) && $_GET['fetch'] === 'all_payments') {
      
      $sql = "
        SELECT 
          l.id AS lot_id,
          l.owner_id,
          l.block_number,
          l.lot_number,
          l.lot_price,
          l.payment_type,
          l.payment_amount,
          l.down_payment_amount,
          l.payment_deadline,
          l.payment_term_years,
          l.payment_due_day,
          l.status,
          IFNULL(tx.total_paid, 0) AS total_paid,
          GREATEST(IFNULL(l.lot_price, 0) - IFNULL(tx.total_paid, 0), 0) AS balance_due,
          tx.last_payment_date,
          IFNULL(tx.paid_months_count, 0) AS paid_months_count,
          t.turnover_date,
          t.title_released,
          t.remarks AS turnover_remarks,
          ll.location_name,
          COALESCE(
            NULLIF(TRIM(CONCAT(IFNULL(u.first_name, ''), ' ', IFNULL(u.last_name, ''))), ''),
            NULLIF(TRIM(rv.reserved_client_name), '')
          ) AS owner_name,
          COALESCE(NULLIF(TRIM(u.email), ''), NULLIF(TRIM(rv.reserved_client_email), '')) AS email,
          COALESCE(NULLIF(TRIM(u.mobile_number), ''), NULLIF(TRIM(rv.reserved_client_phone), '')) AS mobile_number
        FROM lots l
        LEFT JOIN (
          SELECT lot_id, IFNULL(SUM(amount),0) AS total_paid, MAX(payment_date) AS last_payment_date,
                 COUNT(DISTINCT DATE_FORMAT(payment_date,'%Y-%m')) AS paid_months_count
          FROM lot_payment_transactions
          GROUP BY lot_id
        ) tx ON tx.lot_id = l.id
        LEFT JOIN (
          SELECT 
            v.lot_id,
            TRIM(CONCAT(IFNULL(v.client_first_name, ''), ' ', IFNULL(v.client_middle_name, ''), ' ', IFNULL(v.client_last_name, ''))) AS reserved_client_name,
            v.client_email AS reserved_client_email,
            v.client_phone AS reserved_client_phone
          FROM viewings v
          INNER JOIN (
            SELECT lot_id, MAX(id) AS latest_viewing_id
            FROM viewings
            WHERE status IN ('scheduled', 'approved')
            GROUP BY lot_id
          ) latest_v ON latest_v.latest_viewing_id = v.id
        ) rv ON rv.lot_id = l.id
        LEFT JOIN lot_turnovers t ON t.lot_id = l.id
        LEFT JOIN lot_locations ll ON l.location_id = ll.id
        LEFT JOIN user_accounts u ON l.owner_id = u.id
        WHERE (l.owner_id IS NOT NULL OR rv.lot_id IS NOT NULL)
          AND COALESCE(
            NULLIF(TRIM(CONCAT(IFNULL(u.first_name, ''), ' ', IFNULL(u.last_name, ''))), ''),
            NULLIF(TRIM(rv.reserved_client_name), '')
          ) IS NOT NULL
        ORDER BY ll.location_name ASC, l.block_number ASC, l.lot_number ASC
      ";
      
      $result = mysqli_query($conn, $sql);
      $payments = [];
      
      if ($result) {
          while ($row = mysqli_fetch_assoc($result)) {
            $normalizedStatus = normalizeLotStatus($row['status'] ?? 'Available');
              $payments[] = [
                  'lot_id'           => (int)$row['lot_id'],
                  'owner_id'         => $row['owner_id'] !== null ? (int)$row['owner_id'] : null,
                  'block_number'     => $row['block_number'],
                  'lot_number'       => $row['lot_number'],
                  'lot_price'        => $row['lot_price'],
                  'payment_type'     => $row['payment_type'],
                  'payment_amount'   => $row['payment_amount'],
                  'down_payment_amount' => $row['down_payment_amount'],
                  'payment_deadline' => $row['payment_deadline'],
                  'payment_term_years' => $row['payment_term_years'],
                  'payment_due_day'  => $row['payment_due_day'],
                    'total_paid'       => $row['total_paid'],
                    'balance_due'      => $row['balance_due'],
                    'last_payment_date'=> $row['last_payment_date'],
                    'paid_months_count'=> (int)$row['paid_months_count'],
                    'turnover_date'    => $row['turnover_date'],
                    'title_released'   => $row['title_released'],
                    'turnover_remarks' => $row['turnover_remarks'],
              'status'           => $normalizedStatus,
              'workflow_stage'   => deriveLotWorkflowStage($normalizedStatus, $row['payment_type'] ?? ''),
                  'location_name'    => $row['location_name'],
                  'owner_name'       => $row['owner_name'],
                  'email'            => $row['email'],
                  'mobile_number'    => $row['mobile_number']
              ];
          }
      }
      
      header('Content-Type: application/json');
      echo json_encode(['success' => true, 'payments' => $payments]);
      exit;
  }

  // =============================================
  // PAYMENT HISTORY BY LOT (AJAX: ?fetch=payment_transactions&lot_id=..)
  // =============================================
  if ($_SERVER['REQUEST_METHOD'] === 'GET' &&
      isset($_GET['fetch']) && $_GET['fetch'] === 'payment_transactions') {
      header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
      header('Pragma: no-cache');
      header('Expires: 0');
      $lot_id = intval($_GET['lot_id'] ?? 0);
      if (!$lot_id) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid lot ID']);
        exit;
      }

      $txQuery = "
        SELECT t.id, t.amount, t.payment_date, t.payment_method, t.remarks,
               CONCAT(u.first_name, ' ', u.last_name) AS paid_by
        FROM lot_payment_transactions t
        LEFT JOIN user_accounts u ON u.id = t.user_id
        WHERE t.lot_id = $lot_id
        ORDER BY t.payment_date ASC, t.id ASC";
      $txResult = mysqli_query($conn, $txQuery);
      $transactions = [];
      if ($txResult) {
        while ($row = mysqli_fetch_assoc($txResult)) {
          $transactions[] = $row;
        }
      } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Database query failed: ' . mysqli_error($conn)]);
        exit;
      }

      // Safety sort: ensure oldest records are always returned first.
      usort($transactions, function ($a, $b) {
        $da = strtotime((string)($a['payment_date'] ?? '')) ?: 0;
        $db = strtotime((string)($b['payment_date'] ?? '')) ?: 0;
        if ($da === $db) {
          return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
        }
        return $da <=> $db;
      });

      header('Content-Type: application/json');
      echo json_encode(['success' => true, 'transactions' => $transactions]);
      exit;
  }

  if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch']) && $_GET['fetch'] === 'lot_history') {
      header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
      header('Pragma: no-cache');
      header('Expires: 0');
      $lot_id = intval($_GET['lot_id'] ?? 0);
      if (!$lot_id) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid lot ID']);
        exit;
      }

      $rows = [];
      if (isset($conn) && (is_resource($conn) || $conn instanceof mysqli) && hasTable($conn, 'lot_status_history')) {
          $historyQuery = "SELECT event_type, event_date, previous_owner_id, previous_owner_name, previous_owner_email, paid_amount, refund_amount, company_amount, remarks, created_at FROM lot_status_history WHERE lot_id = ? ORDER BY event_date DESC, id DESC";
          $stmt = $conn->prepare($historyQuery);
          if ($stmt) {
              $stmt->bind_param('i', $lot_id);
              if ($stmt->execute()) {
                  $res = $stmt->get_result();
                  while ($row = $res->fetch_assoc()) {
                      $rows[] = $row;
                  }
              } else {
                  header('Content-Type: application/json');
                  echo json_encode(['success' => false, 'error' => 'Database query failed: ' . $stmt->error]);
                  exit;
              }
              $stmt->close();
          } else {
              header('Content-Type: application/json');
              echo json_encode(['success' => false, 'error' => 'Database prepare failed: ' . $conn->error]);
              exit;
          }
      }

      header('Content-Type: application/json');
      echo json_encode(['success' => true, 'history' => $rows]);
      exit;
  }

  // =============================================
  // TURNOVER INFO (AJAX: ?fetch=turnover_info&lot_id=..)
  // =============================================
  if ($_SERVER['REQUEST_METHOD'] === 'GET' &&
      isset($_GET['fetch']) && $_GET['fetch'] === 'turnover_info') {
      $lot_id = intval($_GET['lot_id'] ?? 0);
      if (!$lot_id) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid lot ID']);
        exit;
      }

      $turnoverQuery = "SELECT lot_id, turnover_date, title_released, remarks, updated_at FROM lot_turnovers WHERE lot_id = $lot_id LIMIT 1";
      $turnoverResult = mysqli_query($conn, $turnoverQuery);
      $turnover = $turnoverResult ? mysqli_fetch_assoc($turnoverResult) : null;

      header('Content-Type: application/json');
      echo json_encode(['success' => true, 'turnover' => $turnover]);
      exit;
  }

  // =============================================
  // ALL LOT OWNERS (AJAX: ?fetch=all_lot_owners)
  // =============================================
  if ($_SERVER['REQUEST_METHOD'] === 'GET' &&
      isset($_GET['fetch']) && $_GET['fetch'] === 'all_lot_owners') {
      $location_id = isset($_GET['location_id']) ? intval($_GET['location_id']) : 0;
      $locationWhere = $location_id > 0 ? " AND l.location_id = $location_id " : '';
      
      $sql = "
        SELECT 
          l.id AS lot_id,
          l.location_id,
          l.block_number,
          l.lot_number,
          l.status,
          ll.location_name,
          u.id AS user_id,
          COALESCE(
            NULLIF(TRIM(CONCAT(IFNULL(u.first_name, ''), ' ', IFNULL(u.last_name, ''))), ''),
            NULLIF(TRIM(rv.reserved_client_name), '')
          ) AS owner_name,
          COALESCE(NULLIF(TRIM(u.email), ''), NULLIF(TRIM(rv.reserved_client_email), '')) AS email,
          COALESCE(NULLIF(TRIM(u.mobile_number), ''), NULLIF(TRIM(rv.reserved_client_phone), '')) AS mobile_number
        FROM lots l
        LEFT JOIN (
          SELECT 
            v.lot_id,
            TRIM(CONCAT(IFNULL(v.client_first_name, ''), ' ', IFNULL(v.client_middle_name, ''), ' ', IFNULL(v.client_last_name, ''))) AS reserved_client_name,
            v.client_email AS reserved_client_email,
            v.client_phone AS reserved_client_phone
          FROM viewings v
          INNER JOIN (
            SELECT lot_id, MAX(id) AS latest_viewing_id
            FROM viewings
            WHERE status IN ('scheduled', 'approved')
            GROUP BY lot_id
          ) latest_v ON latest_v.latest_viewing_id = v.id
        ) rv ON rv.lot_id = l.id
        LEFT JOIN lot_locations ll ON l.location_id = ll.id
        LEFT JOIN user_accounts u ON l.owner_id = u.id
        WHERE (l.owner_id IS NOT NULL OR rv.lot_id IS NOT NULL)
          AND COALESCE(
            NULLIF(TRIM(CONCAT(IFNULL(u.first_name, ''), ' ', IFNULL(u.last_name, ''))), ''),
            NULLIF(TRIM(rv.reserved_client_name), '')
          ) IS NOT NULL
        $locationWhere
        ORDER BY ll.location_name ASC, l.block_number ASC, l.lot_number ASC
      ";
      
      $result = mysqli_query($conn, $sql);
      $owners = [];
      
      if ($result) {
          while ($row = mysqli_fetch_assoc($result)) {
            $normalizedStatus = normalizeLotStatus($row['status'] ?? 'Available');
              $owners[] = [
                  'lot_id'          => (int)$row['lot_id'],
                  'location_id'     => (int)$row['location_id'],
                  'user_id'         => (int)$row['user_id'],
                  'block_number'    => $row['block_number'],
                  'lot_number'      => $row['lot_number'],
              'status'          => $normalizedStatus,
                  'location_name'   => $row['location_name'],
                  'owner_name'      => $row['owner_name'],
                  'email'           => $row['email'],
                  'mobile_number'   => $row['mobile_number']
              ];
          }
      }
      
      header('Content-Type: application/json');
      echo json_encode(['success' => true, 'owners' => $owners]);
      exit;
  }

  // =============================================
  // MARK LOT STATUS AS READ (AJAX: POST action=mark_lot_status_read)
  // =============================================
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_lot_status_read') {
    $sql = "UPDATE lot_status_history SET is_read = 1 WHERE event_type = 'surrender' AND is_read = 0";
    if ($conn->query($sql)) {
      header('Content-Type: application/json');
      echo json_encode(['success' => true, 'message' => 'All lot status records marked as read']);
    } else {
      header('Content-Type: application/json');
      echo json_encode(['success' => false, 'message' => 'Error marking records as read']);
    }
    exit;
  }

  // MARK ALL NOTIFICATIONS AS READ (AJAX: POST action=mark_notifications_read)
  // =============================================
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_notifications_read') {
    $sql = "UPDATE notifications SET is_read = 1 WHERE is_read = 0";
    if ($conn->query($sql)) {
      header('Content-Type: application/json');
      echo json_encode(['success' => true, 'message' => 'All notifications marked as read']);
    } else {
      header('Content-Type: application/json');
      echo json_encode(['success' => false, 'message' => 'Error marking notifications as read']);
    }
    exit;
  }

  // =============================================
  // LOTS CRUD (AJAX: POST action=save/delete/bulk_delete)
  // =============================================

  // Save / update lot
  if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
      isset($_POST['action']) && $_POST['action'] === 'save') {

      $block_number = mysqli_real_escape_string($conn, $_POST['block_number']);
      $lot_number   = mysqli_real_escape_string($conn, $_POST['lot_number']);
      $lot_size     = mysqli_real_escape_string($conn, $_POST['lot_size']);
      $lot_price    = mysqli_real_escape_string($conn, $_POST['lot_price']);
      $location_id  = mysqli_real_escape_string($conn, $_POST['location_id']);
      $status       = normalizeLotStatus(isset($_POST['status']) ? mysqli_real_escape_string($conn, $_POST['status']) : 'Available');
        $payment_type = isset($_POST['payment_type']) ? mysqli_real_escape_string($conn, $_POST['payment_type']) : 'Not Applicable';
        $payment_amount_raw = isset($_POST['payment_amount']) ? trim($_POST['payment_amount']) : '';
        $payment_deadline_raw = isset($_POST['payment_deadline']) ? trim($_POST['payment_deadline']) : '';
        $commission_amount_raw = isset($_POST['commission_amount']) ? trim((string)$_POST['commission_amount']) : '';
        $payment_amount = 'NULL';
        $payment_deadline = 'NULL';
        $commission_amount = 'NULL';

          if ($commission_amount_raw !== '') {
            $normalizedCommission = str_replace(',', '', $commission_amount_raw);
            if (!is_numeric($normalizedCommission) || (float)$normalizedCommission < 0) {
              header('Content-Type: application/json');
              echo json_encode(['success' => false, 'error' => 'Please enter a valid commission amount']);
              exit;
            }
            $commission_amount = (float)$normalizedCommission;
          }

          if (!in_array($payment_type, ['Down Payment', 'Fully Paid', 'Not Applicable'], true)) {
          header('Content-Type: application/json');
          echo json_encode(['success' => false, 'error' => 'Invalid payment type']);
          exit;
        }

          if ($payment_type === 'Down Payment' && $payment_deadline_raw !== '') {
            $deadlineObj = DateTime::createFromFormat('Y-m-d', $payment_deadline_raw);
            if (!$deadlineObj || $deadlineObj->format('Y-m-d') !== $payment_deadline_raw) {
              header('Content-Type: application/json');
              echo json_encode(['success' => false, 'error' => 'Please enter a valid payment deadline']);
              exit;
            }
            $payment_deadline = "'" . mysqli_real_escape_string($conn, $payment_deadline_raw) . "'";
          }

          if ($status === 'Available') {
            $payment_type = 'Not Applicable';
            $payment_amount = 'NULL';
            $payment_deadline = 'NULL';
          } elseif ($status === 'Paid') {
            $payment_type = 'Fully Paid';
            $payment_amount = is_numeric($lot_price) ? (float)$lot_price : 'NULL';
            $payment_deadline = 'NULL';
          } elseif ($payment_type === 'Down Payment') {
            if ($payment_amount_raw === '' || !is_numeric($payment_amount_raw) || (float)$payment_amount_raw <= 0) {
              header('Content-Type: application/json');
              echo json_encode(['success' => false, 'error' => 'Please enter a valid down payment amount']);
              exit;
            }
            $payment_amount = (float)$payment_amount_raw;
          } else {
            $payment_type = 'Not Applicable';
            $payment_amount = 'NULL';
            $payment_deadline = 'NULL';
          }

      if (!empty($_POST['lot_id'])) {
          $lot_id = intval($_POST['lot_id']);
          $updateQuery = "UPDATE lots SET
                          block_number = '$block_number',
                          lot_number   = '$lot_number',
                          lot_size     = '$lot_size',
                          lot_price    = '$lot_price',
                          location_id  = '$location_id',
                  status       = '$status',
                  payment_type = '$payment_type',
                          payment_amount = $payment_amount,
                      commission_amount = $commission_amount,
                          payment_deadline = $payment_deadline
                          WHERE id = $lot_id";

          $success = mysqli_query($conn, $updateQuery);
          if ($success) {
            // Keep blueprint pin color/status accurate when status is edited from Manage Lots.
            $syncPinStatusQuery = "UPDATE pin_locations SET pin_status = '$status' WHERE lot_id = $lot_id";
            mysqli_query($conn, $syncPinStatusQuery);
          }
          $msg     = $success ? 'Lot updated successfully' : mysqli_error($conn);
      } else {
          $insertQuery = "INSERT INTO lots (block_number, lot_number, lot_size, lot_price, location_id, status, payment_type, payment_amount, commission_amount, payment_deadline)
            VALUES ('$block_number', '$lot_number', '$lot_size', '$lot_price', '$location_id', '$status', '$payment_type', $payment_amount, $commission_amount, $payment_deadline)";
          $success = mysqli_query($conn, $insertQuery);
          $msg     = $success ? 'Lot added successfully' : mysqli_error($conn);
      }

      header('Content-Type: application/json');
      echo json_encode(['success' => (bool)$success, 'message' => $msg]);
      exit;
  }

  // Delete single lot
  if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
      isset($_POST['action']) && $_POST['action'] === 'delete') {

      $lot_id      = intval($_POST['lot_id']);
      $deleteQuery = "DELETE FROM lots WHERE id = $lot_id";
      $success     = mysqli_query($conn, $deleteQuery);

      header('Content-Type: application/json');
      echo json_encode([
          'success' => (bool)$success,
          'message' => $success ? 'Lot deleted successfully' : mysqli_error($conn)
      ]);
      exit;
  }

  // Bulk delete lots
  if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
      isset($_POST['action']) && $_POST['action'] === 'bulk_delete') {

      $ids = json_decode($_POST['lot_ids'], true);
      if (!is_array($ids) || empty($ids)) {
          header('Content-Type: application/json');
          echo json_encode(['success' => false, 'error' => 'No lots selected']);
          exit;
      }

      $idList      = implode(',', array_map('intval', $ids));
      $deleteQuery = "DELETE FROM lots WHERE id IN ($idList)";
      $success     = mysqli_query($conn, $deleteQuery);

      header('Content-Type: application/json');
      echo json_encode([
          'success' => (bool)$success,
          'message' => $success ? 'Lots deleted successfully' : mysqli_error($conn)
      ]);
      exit;
  }


  // =============================================
  // PIN LOCATION ENDPOINTS (AJAX)
  // =============================================
  
  // GET: Fetch blueprint for a location
  if ($_SERVER['REQUEST_METHOD'] === 'GET' && 
      isset($_GET['fetch']) && $_GET['fetch'] === 'blueprint') {
      $lot_id = intval($_GET['lot_id'] ?? 0);
      
      // Get lot and its location
      $lotQuery = "SELECT l.*, loc.location_name FROM lots l 
                   LEFT JOIN lot_locations loc ON l.location_id = loc.id 
                   WHERE l.id = $lot_id";
      $lotResult = mysqli_query($conn, $lotQuery);
      $lot = $lotResult ? mysqli_fetch_assoc($lotResult) : null;
      
      if (!$lot) {
          header('Content-Type: application/json');
          echo json_encode(['success' => false, 'error' => 'Lot not found']);
          exit;
      }
      
      // Get blueprint for this location
      $blueprintQuery = "SELECT filename FROM blueprints WHERE location_id = " . $lot['location_id'] . " LIMIT 1";
      $blueprintResult = mysqli_query($conn, $blueprintQuery);
      $blueprint = $blueprintResult ? mysqli_fetch_assoc($blueprintResult) : null;
      
        // Get existing pin location for current lot
        $pinQuery = "SELECT polygon_coordinates, pin_status FROM pin_locations WHERE lot_id = $lot_id";
        $pinResult = mysqli_query($conn, $pinQuery);
        $pin = $pinResult ? mysqli_fetch_assoc($pinResult) : null;

        // Get all saved pins in this location (for multi-lot rendering on same blueprint)
        $allPins = [];
        $allPinsQuery = "
          SELECT p.lot_id, p.polygon_coordinates, p.pin_status
          FROM pin_locations p
          INNER JOIN lots l ON l.id = p.lot_id
          WHERE l.location_id = " . (int)$lot['location_id'];
        $allPinsResult = mysqli_query($conn, $allPinsQuery);
        if ($allPinsResult) {
          while ($row = mysqli_fetch_assoc($allPinsResult)) {
            $allPins[] = [
              'lot_id' => (int)$row['lot_id'],
              'coordinates' => json_decode($row['polygon_coordinates'], true),
              'pin_status' => $row['pin_status'] ?: 'Available'
            ];
          }
        }
      
      header('Content-Type: application/json');
      echo json_encode([
          'success' => true,
          'lot' => $lot,
          'blueprint' => $blueprint ? 'blueprints/' . $blueprint['filename'] : null,
          'pin' => $pin ? json_decode($pin['polygon_coordinates'], true) : null,
          'pin_status' => $pin ? $pin['pin_status'] : null,
          'all_pins' => $allPins
      ]);
      exit;
  }
  
  // POST: Save pin location
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && 
      isset($_POST['action']) && $_POST['action'] === 'save_pin') {
      
      $lot_id = intval($_POST['lot_id'] ?? 0);
      $coordinates = mysqli_real_escape_string($conn, $_POST['polygon_coordinates'] ?? '');
      $pin_status = mysqli_real_escape_string($conn, $_POST['pin_status'] ?? 'Available');
      
      if (!$lot_id || !$coordinates) {
          header('Content-Type: application/json');
          echo json_encode(['success' => false, 'error' => 'Missing lot_id or coordinates']);
          exit;
      }
      
      // Check if pin exists
      $checkQuery = "SELECT id FROM pin_locations WHERE lot_id = $lot_id";
      $checkResult = mysqli_query($conn, $checkQuery);
      $exists = mysqli_fetch_assoc($checkResult);
      
      if ($exists) {
          // Update
          $updateQuery = "UPDATE pin_locations SET polygon_coordinates = '$coordinates', pin_status = '$pin_status' WHERE lot_id = $lot_id";
          $success = mysqli_query($conn, $updateQuery);
      } else {
          // Insert
          $insertQuery = "INSERT INTO pin_locations (lot_id, polygon_coordinates, pin_status) VALUES ($lot_id, '$coordinates', '$pin_status')";
          $success = mysqli_query($conn, $insertQuery);
      }
      
      // Automatically update the lot's status to match the pin_status
      if ($success) {
          $updateLotStatusQuery = "UPDATE lots SET status = '$pin_status' WHERE id = $lot_id";
          mysqli_query($conn, $updateLotStatusQuery);
      }
      
      header('Content-Type: application/json');
      echo json_encode([
          'success' => (bool)$success,
          'message' => $success ? 'Pin location and lot status saved successfully' : mysqli_error($conn)
      ]);
      exit;
  }
  
      // =====================================================
      // OWNER REGISTRATION HELPERS (AJAX GET)
      // =====================================================
      if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch']) && $_GET['fetch'] === 'owner_users') {
        $sql = "
        SELECT id, first_name, middle_name, last_name, email, mobile_number
        FROM user_accounts
        ORDER BY first_name ASC, last_name ASC
        ";
        $result = mysqli_query($conn, $sql);
        $users = [];

        if ($result) {
          while ($row = mysqli_fetch_assoc($result)) {
            $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            $users[] = [
              'id' => (int)$row['id'],
              'name' => preg_replace('/\s+/', ' ', $fullName),
              'email' => $row['email'] ?? '',
              'mobile_number' => $row['mobile_number'] ?? ''
            ];
          }
        }

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'users' => $users]);
        exit;
      }

      if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch']) && $_GET['fetch'] === 'owner_assignable_lots') {
        $location_id = isset($_GET['location_id']) ? intval($_GET['location_id']) : 0;
        $whereLocation = $location_id > 0 ? " AND l.location_id = $location_id " : '';

        $sql = "
        SELECT l.id, l.block_number, l.lot_number, l.status, l.location_id, ll.location_name
        FROM lots l
        LEFT JOIN lot_locations ll ON l.location_id = ll.id
        WHERE l.owner_id IS NULL
        $whereLocation
        ORDER BY ll.location_name ASC, l.block_number ASC, l.lot_number ASC
        ";
        $result = mysqli_query($conn, $sql);
        $lots = [];

        if ($result) {
          while ($row = mysqli_fetch_assoc($result)) {
            $lots[] = [
              'id' => (int)$row['id'],
              'block_number' => $row['block_number'],
              'lot_number' => $row['lot_number'],
              'status' => $row['status'],
              'location_id' => (int)$row['location_id'],
              'location_name' => $row['location_name']
            ];
          }
        }

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'lots' => $lots]);
        exit;
      }

  // GET: Fetch pin location
  if ($_SERVER['REQUEST_METHOD'] === 'GET' && 
      isset($_GET['fetch']) && $_GET['fetch'] === 'pin_location') {
      $lot_id = intval($_GET['lot_id'] ?? 0);
      
      $pinQuery = "SELECT polygon_coordinates FROM pin_locations WHERE lot_id = $lot_id";
      $pinResult = mysqli_query($conn, $pinQuery);
      $pin = $pinResult ? mysqli_fetch_assoc($pinResult) : null;
      
      header('Content-Type: application/json');
      echo json_encode([
          'success' => true,
          'pin' => $pin ? json_decode($pin['polygon_coordinates'], true) : null
      ]);
      exit;
  }


    // POST: Save new location
    if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
      isset($_POST['action']) && $_POST['action'] === 'save_location') {
      header('Content-Type: application/json');

      $location_name = trim((string)($_POST['location_name'] ?? ''));
      $latitude = floatval($_POST['latitude'] ?? 0);
      $longitude = floatval($_POST['longitude'] ?? 0);

      if ($location_name === '' || $latitude == 0.0 || $longitude == 0.0) {
        echo json_encode(['success' => false, 'error' => 'Missing location name or coordinates']);
        exit;
      }

      $insertLocationStmt = $conn->prepare("INSERT INTO lot_locations (location_name, latitude, longitude) VALUES (?, ?, ?)");
      if (!$insertLocationStmt) {
        echo json_encode(['success' => false, 'error' => 'Failed to prepare location insert: ' . $conn->error]);
        exit;
      }

      $insertLocationStmt->bind_param('sdd', $location_name, $latitude, $longitude);
      $success = $insertLocationStmt->execute();
      $insertLocationStmt->close();

      if (!$success) {
        echo json_encode([
          'success' => false,
          'error' => mysqli_error($conn)
        ]);
        exit;
      }

      $location_id = mysqli_insert_id($conn);
      $warning = '';

      // Handle optional blueprint upload without breaking location creation.
      if (isset($_FILES['blueprint']) && $_FILES['blueprint']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $ext = strtolower(pathinfo($_FILES['blueprint']['name'], PATHINFO_EXTENSION));

        if (in_array($ext, $allowed, true)) {
          $uploadDir = 'blueprints/';
          if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
          }

          $newName = uniqid('bp_', true) . '.' . $ext;
          if (move_uploaded_file($_FILES['blueprint']['tmp_name'], $uploadDir . $newName)) {
            $stmt = $conn->prepare("INSERT INTO blueprints (location_id, filename, uploaded_at) VALUES (?, ?, NOW())");
            if ($stmt) {
              $stmt->bind_param('is', $location_id, $newName);
              if (!$stmt->execute()) {
                $warning = ' Blueprint was uploaded but not linked in database.';
              }
              $stmt->close();
            } else {
              $warning = ' Blueprint was uploaded but blueprints table insert is unavailable.';
            }
          } else {
            $warning = ' Blueprint upload failed, but location was created.';
          }
        } else {
          $warning = ' Blueprint file type is not supported.';
        }
      }

      echo json_encode([
        'success' => true,
        'message' => 'Location saved successfully.' . $warning,
        'location_id' => $location_id
      ]);
      exit;
    }

    // =====================================================
    // UPDATE LOT STATUS (AJAX: POST action=update_lot_status)
    // =====================================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && 
        isset($_POST['action']) && $_POST['action'] === 'update_lot_status') {
        header('Content-Type: application/json');
        
        $lot_id = intval($_POST['lot_id'] ?? 0);
        $status = trim((string)($_POST['status'] ?? ''));
        
        if (!$lot_id || !in_array($status, ['Available', 'Reserved', 'Reservation', 'Installment', 'Installments', 'Sold', 'Paid'], true)) {
            echo json_encode(['success' => false, 'error' => 'Invalid lot ID or status']);
            exit;
        }

        if ($status === 'Reservation') {
          $status = 'Reserved';
        }

        if ($status === 'Sold') {
          $status = 'Paid';
        }

        if ($status === 'Installments' || $status === 'Installment') {
          $updateQuery = "UPDATE lots SET status = 'Installment', payment_type = 'Down Payment' WHERE id = $lot_id";
          $pinStatus = 'Installment';
        } elseif ($status === 'Available') {
          $updateQuery = "UPDATE lots SET status = 'Available', payment_type = 'Not Applicable', payment_amount = NULL, payment_deadline = NULL WHERE id = $lot_id";
          $pinStatus = 'Available';
        } elseif ($status === 'Paid') {
          $updateQuery = "UPDATE lots SET status = 'Paid', payment_type = 'Fully Paid', payment_deadline = NULL, payment_amount = lot_price WHERE id = $lot_id";
          $pinStatus = 'Paid';
        } else {
          $updateQuery = "UPDATE lots SET status = 'Reserved', payment_type = 'Not Applicable', payment_amount = NULL, payment_deadline = NULL WHERE id = $lot_id";
          $pinStatus = 'Reserved';
        }
        $success = mysqli_query($conn, $updateQuery);
        
        if ($success) {
            mysqli_query($conn, "UPDATE pin_locations SET pin_status = '" . mysqli_real_escape_string($conn, $pinStatus) . "' WHERE lot_id = $lot_id");
            echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Database update failed: ' . mysqli_error($conn)]);
        }
        exit;
    }

    // =====================================================
    // UPDATE INSTALLMENT PLAN (AJAX: POST action=update_installment_plan)
    // =====================================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['action']) && $_POST['action'] === 'update_installment_plan') {
      header('Content-Type: application/json');

      $lot_id = intval($_POST['lot_id'] ?? 0);
      $downPaymentRaw = trim((string)($_POST['down_payment_amount'] ?? ''));
      $deadlineRaw = trim((string)($_POST['payment_deadline'] ?? ''));
      $termYears = intval($_POST['payment_term_years'] ?? 0);
      $dueDay = intval($_POST['payment_due_day'] ?? 0);

      if (!$lot_id) {
        echo json_encode(['success' => false, 'error' => 'Invalid lot ID']);
        exit;
      }

      if ($termYears <= 0 || $termYears > 5) {
        echo json_encode(['success' => false, 'error' => 'Please provide a valid payment term in years (1-5 only).']);
        exit;
      }

      if ($dueDay < 1 || $dueDay > 31) {
        echo json_encode(['success' => false, 'error' => 'Please provide a valid due day in a month (1-31).']);
        exit;
      }

      $downPaymentAmount = null;
      if ($downPaymentRaw !== '') {
        if (!is_numeric($downPaymentRaw) || (float)$downPaymentRaw < 0) {
          echo json_encode(['success' => false, 'error' => 'Please provide a valid down payment amount.']);
          exit;
        }
        $downPaymentAmount = (float)$downPaymentRaw;
      }

      $deadlineSql = 'NULL';
      if ($deadlineRaw !== '') {
        $deadlineObj = DateTime::createFromFormat('Y-m-d', $deadlineRaw);
        if (!$deadlineObj || $deadlineObj->format('Y-m-d') !== $deadlineRaw) {
          echo json_encode(['success' => false, 'error' => 'Please provide a valid payment deadline.']);
          exit;
        }
        $deadlineSql = "'" . mysqli_real_escape_string($conn, $deadlineRaw) . "'";
      } else {
        $computedDeadline = calculateNextMonthlyDueDate($dueDay);
        if ($computedDeadline !== null) {
          $deadlineRaw = $computedDeadline;
          $deadlineSql = "'" . mysqli_real_escape_string($conn, $computedDeadline) . "'";
        }
      }

      $lotCheckRes = mysqli_query($conn, "SELECT status, lot_price FROM lots WHERE id = $lot_id LIMIT 1");
      $lotCheck = $lotCheckRes ? mysqli_fetch_assoc($lotCheckRes) : null;
      if (!$lotCheck) {
        echo json_encode(['success' => false, 'error' => 'Lot not found.']);
        exit;
      }

      $currentStatus = normalizeLotStatus($lotCheck['status'] ?? 'Available');
      if ($currentStatus === 'Paid') {
        echo json_encode(['success' => false, 'error' => 'Cannot set installment plan for a fully paid lot.']);
        exit;
      }

      $lotPrice = (float)($lotCheck['lot_price'] ?? 0);
      if ($lotPrice <= 0) {
        echo json_encode(['success' => false, 'error' => 'Lot price must be greater than zero before creating an installment plan.']);
        exit;
      }

      if ($downPaymentAmount !== null && $downPaymentAmount >= $lotPrice) {
        echo json_encode(['success' => false, 'error' => 'Down payment must be less than the lot price.']);
        exit;
      }

      $remainingBalance = $lotPrice - (float)($downPaymentAmount ?? 0);
      $termMonths = $termYears * 12;
      if ($remainingBalance <= 0 || $termMonths <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid remaining balance or payment term.']);
        exit;
      }

      $amount = round($remainingBalance / $termMonths, 2);
      if ($amount <= 0) {
        echo json_encode(['success' => false, 'error' => 'Calculated monthly installment is invalid.']);
        exit;
      }

      $downPaymentSql = $downPaymentAmount !== null ? (string)$downPaymentAmount : 'NULL';

      $updateSql = "UPDATE lots
                    SET payment_type = 'Down Payment',
                        payment_amount = $amount,
                        down_payment_amount = $downPaymentSql,
                        payment_deadline = $deadlineSql,
                        payment_term_years = $termYears,
                        payment_due_day = $dueDay,
                        status = 'Installment'
                    WHERE id = $lot_id";

      $ok = mysqli_query($conn, $updateSql);
      if (!$ok) {
        echo json_encode(['success' => false, 'error' => 'Failed to update installment plan: ' . mysqli_error($conn)]);
        exit;
      }

      mysqli_query($conn, "UPDATE pin_locations SET pin_status = 'Installment' WHERE lot_id = $lot_id AND pin_status <> 'Paid'");

      echo json_encode([
        'success' => true,
        'message' => 'Installment plan updated successfully.',
        'payment_amount' => $amount,
        'down_payment_amount' => $downPaymentAmount,
        'remaining_balance' => $remainingBalance,
        'payment_deadline' => $deadlineRaw !== '' ? $deadlineRaw : null,
        'payment_term_years' => $termYears,
        'payment_due_day' => $dueDay
      ]);
      exit;
    }

    // =====================================================
    // RECORD PAYMENT TRANSACTION (AJAX: POST action=add_payment_transaction)
    // =====================================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['action']) && $_POST['action'] === 'add_payment_transaction') {
      header('Content-Type: application/json');

      $lot_id = intval($_POST['lot_id'] ?? 0);
      $amountRaw = trim((string)($_POST['amount'] ?? ''));
      $payment_date = trim((string)($_POST['payment_date'] ?? ''));
      $payment_method = mysqli_real_escape_string($conn, trim((string)($_POST['payment_method'] ?? 'Cash')));
      $remarks = mysqli_real_escape_string($conn, trim((string)($_POST['remarks'] ?? '')));

      if (!$lot_id || $amountRaw === '' || !is_numeric($amountRaw) || (float)$amountRaw <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid lot or amount']);
        exit;
      }

      if ($payment_date === '') {
        $payment_date = date('Y-m-d');
      }

      $lotQuery = "SELECT id, owner_id, lot_price, payment_due_day, payment_type, payment_amount FROM lots WHERE id = $lot_id LIMIT 1";
      $lotResult = mysqli_query($conn, $lotQuery);
      $lot = $lotResult ? mysqli_fetch_assoc($lotResult) : null;
      if (!$lot) {
        echo json_encode(['success' => false, 'error' => 'Lot not found']);
        exit;
      }

      $ownerId = $lot['owner_id'] !== null ? (int)$lot['owner_id'] : null;
      $amount = (float)$amountRaw;
      $userValue = $ownerId ? (string)$ownerId : 'NULL';

      $insertTx = "
        INSERT INTO lot_payment_transactions (lot_id, user_id, amount, payment_date, payment_method, remarks)
        VALUES ($lot_id, $userValue, $amount, '" . mysqli_real_escape_string($conn, $payment_date) . "', '$payment_method', '" . $remarks . "')";
      $ok = mysqli_query($conn, $insertTx);
      if (!$ok) {
        echo json_encode(['success' => false, 'error' => 'Failed to save payment: ' . mysqli_error($conn)]);
        exit;
      }

      $sumQuery = "SELECT IFNULL(SUM(amount),0) AS total_paid FROM lot_payment_transactions WHERE lot_id = $lot_id";
      $sumResult = mysqli_query($conn, $sumQuery);
      $sumRow = $sumResult ? mysqli_fetch_assoc($sumResult) : ['total_paid' => 0];
      $totalPaid = (float)($sumRow['total_paid'] ?? 0);
      $lotPrice = (float)($lot['lot_price'] ?? 0);
      $balanceDue = max(0, $lotPrice - $totalPaid);

      if ($totalPaid >= $lotPrice && $lotPrice > 0) {
        mysqli_query($conn, "UPDATE lots SET status = 'Paid', payment_type = 'Fully Paid', payment_amount = $totalPaid, payment_deadline = NULL WHERE id = $lot_id");
        mysqli_query($conn, "UPDATE pin_locations SET pin_status = 'Paid' WHERE lot_id = $lot_id");
      } else {
        $scheduledInstallment = isset($lot['payment_amount']) && (float)$lot['payment_amount'] > 0
          ? (float)$lot['payment_amount']
          : $amount;
        // Keep payment_deadline fixed as the Month 1 anchor of the installment plan.
        mysqli_query($conn, "UPDATE lots SET status = 'Installment', payment_type = 'Down Payment', payment_amount = $scheduledInstallment WHERE id = $lot_id");
        mysqli_query($conn, "UPDATE pin_locations SET pin_status = 'Installment' WHERE lot_id = $lot_id");
      }

      $recipientSql = "
        SELECT
          l.owner_id,
          l.block_number,
          l.lot_number,
          ll.location_name,
          COALESCE(
            NULLIF(TRIM(CONCAT(IFNULL(u.first_name, ''), ' ', IFNULL(u.last_name, ''))), ''),
            NULLIF(TRIM(rv.reserved_client_name), ''),
            NULLIF(TRIM(rv_any.client_name), '')
          ) AS recipient_name,
          COALESCE(
            NULLIF(TRIM(u.email), ''),
            NULLIF(TRIM(rv.reserved_client_email), ''),
            NULLIF(TRIM(rv_any.client_email), '')
          ) AS recipient_email
        FROM lots l
        LEFT JOIN (
          SELECT
            v.lot_id,
            TRIM(CONCAT(IFNULL(v.client_first_name, ''), ' ', IFNULL(v.client_middle_name, ''), ' ', IFNULL(v.client_last_name, ''))) AS reserved_client_name,
            v.client_email AS reserved_client_email
          FROM viewings v
          INNER JOIN (
            SELECT lot_id, MAX(id) AS latest_viewing_id
            FROM viewings
            WHERE status IN ('scheduled', 'approved')
            GROUP BY lot_id
          ) latest_v ON latest_v.latest_viewing_id = v.id
        ) rv ON rv.lot_id = l.id
        LEFT JOIN (
          SELECT
            v2.lot_id,
            TRIM(CONCAT(IFNULL(v2.client_first_name, ''), ' ', IFNULL(v2.client_middle_name, ''), ' ', IFNULL(v2.client_last_name, ''))) AS client_name,
            v2.client_email AS client_email
          FROM viewings v2
          INNER JOIN (
            SELECT lot_id, MAX(id) AS latest_viewing_id
            FROM viewings
            GROUP BY lot_id
          ) latest_any ON latest_any.latest_viewing_id = v2.id
        ) rv_any ON rv_any.lot_id = l.id
        LEFT JOIN lot_locations ll ON ll.id = l.location_id
        LEFT JOIN user_accounts u ON u.id = l.owner_id
        WHERE l.id = $lot_id
        LIMIT 1";
      $recipientRes = mysqli_query($conn, $recipientSql);
      $recipient = $recipientRes ? mysqli_fetch_assoc($recipientRes) : null;

      $systemNotified = false;
      $emailSent = false;
      $emailError = '';

      if ($recipient) {
        $locationName = trim((string)($recipient['location_name'] ?? ''));
        $lotLabel = 'Block ' . (string)($recipient['block_number'] ?? 'N/A') . ', Lot ' . (string)($recipient['lot_number'] ?? 'N/A');
        if ($locationName !== '') {
          $lotLabel .= ' (' . $locationName . ')';
        }

        $formattedAmount = number_format($amount, 2);
        $formattedTotalPaid = number_format($totalPaid, 2);
        $formattedBalance = number_format($balanceDue, 2);
        $paymentDateDisplay = date('F j, Y', strtotime($payment_date));

        $title = 'Payment Recorded Successfully';
        $inAppMessage = "We received your payment of PHP {$formattedAmount} for {$lotLabel} on {$paymentDateDisplay}. Total paid: PHP {$formattedTotalPaid}. Remaining balance: PHP {$formattedBalance}.";

        $recipientUserId = isset($recipient['owner_id']) ? (int)$recipient['owner_id'] : 0;
        if ($recipientUserId > 0) {
          $notifiedShared = insertRecipientNotification(
            $conn,
            'user',
            $recipientUserId,
            $title,
            $inAppMessage,
            'success'
          );
          $notifiedFeed = insertUserNotificationFeed(
            $conn,
            $recipientUserId,
            $title,
            $inAppMessage,
            'success'
          );
          $systemNotified = $notifiedShared || $notifiedFeed;
        }

        $recipientEmail = trim((string)($recipient['recipient_email'] ?? ''));
        if ($recipientEmail !== '') {
          $recipientName = trim((string)($recipient['recipient_name'] ?? ''));
          $mailSubject = 'Nuevo Puerta Payment Confirmation';
          $mailBody = "Hello " . ($recipientName !== '' ? $recipientName : 'Client') . ",\n\n"
            . "Your payment has been recorded successfully.\n"
            . "Lot: {$lotLabel}\n"
            . "Amount Paid: PHP {$formattedAmount}\n"
            . "Payment Date: {$paymentDateDisplay}\n"
            . "Total Paid: PHP {$formattedTotalPaid}\n"
            . "Remaining Balance: PHP {$formattedBalance}\n\n"
            . "Thank you,\nNuevo Puerta";

          $emailSent = sendSystemEmail($recipientEmail, $recipientName, $mailSubject, $mailBody, $emailError);
        }
      }

      echo json_encode([
        'success' => true,
        'message' => 'Payment recorded successfully',
        'total_paid' => $totalPaid,
        'balance_due' => $balanceDue,
        'system_notified' => $systemNotified,
        'email_sent' => $emailSent,
        'email_error' => $emailSent ? null : ($emailError !== '' ? $emailError : null)
      ]);
      exit;
    }

    // =====================================================
    // MARK TURNOVER COMPLETE (AJAX: POST action=mark_turnover_complete)
    // =====================================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['action']) && $_POST['action'] === 'mark_turnover_complete') {
      header('Content-Type: application/json');

      $lot_id = intval($_POST['lot_id'] ?? 0);
      $turnover_date = trim((string)($_POST['turnover_date'] ?? ''));
      $title_released = intval($_POST['title_released'] ?? 0) ? 1 : 0;
      $remarks = mysqli_real_escape_string($conn, trim((string)($_POST['remarks'] ?? '')));

      if (!$lot_id) {
        echo json_encode(['success' => false, 'error' => 'Invalid lot ID']);
        exit;
      }

      if ($turnover_date === '') {
        $turnover_date = date('Y-m-d');
      }

      if ($remarks === '') {
        $remarks = $title_released
          ? mysqli_real_escape_string($conn, 'Ownership title claimed/released at Main Office.')
          : mysqli_real_escape_string($conn, 'Client must claim ownership title at Main Office.');
      }

      $statusCheck = mysqli_query($conn, "
        SELECT l.status, l.payment_type, l.lot_price,
               IFNULL((SELECT SUM(t.amount) FROM lot_payment_transactions t WHERE t.lot_id = l.id), 0) AS total_paid
        FROM lots l
        WHERE l.id = $lot_id
        LIMIT 1
      ");
      $statusRow = $statusCheck ? mysqli_fetch_assoc($statusCheck) : null;
      $status = normalizeLotStatus($statusRow['status'] ?? '');
      $paymentTypeNow = trim((string)($statusRow['payment_type'] ?? ''));
      $lotPriceNow = (float)($statusRow['lot_price'] ?? 0);
      $totalPaidNow = (float)($statusRow['total_paid'] ?? 0);
      $isFullyPaid = ($status === 'Paid') || ($paymentTypeNow === 'Fully Paid') || ($lotPriceNow > 0 && $totalPaidNow >= $lotPriceNow);
      if (!$isFullyPaid) {
        echo json_encode(['success' => false, 'error' => 'Turnover is allowed only for fully paid lots']);
        exit;
      }

      // Keep lot status consistent when turnover is saved for fully paid records.
      if ($status !== 'Paid') {
        mysqli_query($conn, "UPDATE lots SET status = 'Paid', payment_type = 'Fully Paid' WHERE id = $lot_id");
        mysqli_query($conn, "UPDATE pin_locations SET pin_status = 'Paid' WHERE lot_id = $lot_id");
      }

      $upsert = "
        INSERT INTO lot_turnovers (lot_id, turnover_date, title_released, is_confirmed, remarks)
        VALUES ($lot_id, '" . mysqli_real_escape_string($conn, $turnover_date) . "', $title_released, 1, '$remarks')
        ON DUPLICATE KEY UPDATE
          turnover_date = VALUES(turnover_date),
          title_released = VALUES(title_released),
          is_confirmed = 1,
          remarks = VALUES(remarks)";

      $ok = mysqli_query($conn, $upsert);
      if (!$ok) {
        echo json_encode(['success' => false, 'error' => 'Failed to save turnover: ' . mysqli_error($conn)]);
        exit;
      }

      $mobileExpr = "''";
      if (columnExists($conn, 'user_accounts', 'mobile_number')) {
        $mobileExpr = 'u.mobile_number';
      } elseif (columnExists($conn, 'user_accounts', 'phone_number')) {
        $mobileExpr = 'u.phone_number';
      } elseif (columnExists($conn, 'user_accounts', 'mobile')) {
        $mobileExpr = 'u.mobile';
      }

      $ownerQuery = "
        SELECT l.owner_id,
               l.block_number,
               l.lot_number,
               ll.location_name,
               CONCAT(IFNULL(u.first_name,''), ' ', IFNULL(u.last_name,'')) AS owner_name,
           COALESCE(NULLIF(TRIM(u.email), ''), '') AS owner_email,
               COALESCE($mobileExpr, '') AS mobile_number
        FROM lots l
        LEFT JOIN lot_locations ll ON ll.id = l.location_id
        LEFT JOIN user_accounts u ON u.id = l.owner_id
        WHERE l.id = $lot_id
        LIMIT 1";
      $ownerRes = mysqli_query($conn, $ownerQuery);
      $ownerRow = $ownerRes ? mysqli_fetch_assoc($ownerRes) : null;

      $systemNotified = false;
      $smsSent = false;
      $smsError = '';
      $emailSent = false;
      $emailError = '';

      if ($ownerRow && !empty($ownerRow['owner_id'])) {
        $locationName = trim((string)($ownerRow['location_name'] ?? ''));
        $lotLabel = 'Block ' . (string)($ownerRow['block_number'] ?? 'N/A') . ', Lot ' . (string)($ownerRow['lot_number'] ?? 'N/A');
        if ($locationName !== '') {
          $lotLabel .= ' (' . $locationName . ')';
        }

        $inAppMessage = $title_released
          ? 'Your ownership title for ' . $lotLabel . ' is now ready/released at the Main Office. Please coordinate with the office staff for claiming.'
          : 'Your lot turnover for ' . $lotLabel . ' has been processed. You may now proceed to the Main Office to claim your ownership title.';

        $notifiedShared = insertRecipientNotification(
          $conn,
          'user',
          (int)$ownerRow['owner_id'],
          'Turnover and Title Claim Notice',
          $inAppMessage,
          'success'
        );
        $notifiedUserFeed = insertUserNotificationFeed(
          $conn,
          (int)$ownerRow['owner_id'],
          'Turnover and Title Claim Notice',
          $inAppMessage,
          'success'
        );
        $systemNotified = $notifiedShared || $notifiedUserFeed;

        $smsMessage = 'Nuevo Puerta: ' . $inAppMessage;
        $smsSent = sendSmsViaGateway((string)($ownerRow['mobile_number'] ?? ''), $smsMessage, $smsError);

        $ownerEmail = trim((string)($ownerRow['owner_email'] ?? ''));
        if ($ownerEmail !== '') {
          $ownerName = trim((string)($ownerRow['owner_name'] ?? ''));
          $mailSubject = 'Nuevo Puerta Turnover Update';
          $mailBody = "Hello " . ($ownerName !== '' ? $ownerName : 'Client') . ",\n\n"
            . "Your turnover record has been updated.\n"
            . "Property: {$lotLabel}\n"
            . "Turnover Date: " . date('F j, Y', strtotime($turnover_date)) . "\n"
            . "Title Released: " . ($title_released ? 'Yes' : 'Pending claim') . "\n"
            . "Remarks: {$remarks}\n\n"
            . "Please keep this email as your transaction record.\n\n"
            . "Thank you,\nNuevo Puerta";

          $emailSent = sendSystemEmail($ownerEmail, $ownerName, $mailSubject, $mailBody, $emailError);
        }
      }

      echo json_encode([
        'success' => true,
        'message' => 'Turnover updated successfully',
        'system_notified' => $systemNotified,
        'sms_sent' => $smsSent,
        'sms_error' => $smsSent ? null : $smsError,
        'email_sent' => $emailSent,
        'email_error' => $emailSent ? null : ($emailError !== '' ? $emailError : null)
      ]);
      exit;
    }

    // =====================================================
    // REMOVE LOT OWNER (AJAX: POST action=remove_lot_owner)
    // =====================================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && 
        isset($_POST['action']) && $_POST['action'] === 'remove_lot_owner') {
        header('Content-Type: application/json');
        
        $lot_id = intval($_POST['lot_id'] ?? 0);
        
        if (!$lot_id) {
            echo json_encode(['success' => false, 'error' => 'Invalid lot ID']);
            exit;
        }
        
        // Check if the lot has an owner
        $checkQuery = "SELECT owner_id FROM lots WHERE id = $lot_id";
        $checkResult = mysqli_query($conn, $checkQuery);
        if (!$checkResult) {
            echo json_encode(['success' => false, 'error' => 'Database error: ' . mysqli_error($conn)]);
            exit;
        }
        $lot = mysqli_fetch_assoc($checkResult);
        if (!$lot || $lot['owner_id'] === null) {
            echo json_encode(['success' => false, 'error' => 'Lot has no owner to remove']);
            exit;
        }
        
        $updateQuery = "UPDATE lots SET owner_id = NULL, status = 'Available' WHERE id = $lot_id";
        $success = mysqli_query($conn, $updateQuery);
        
        if (!$success) {
            echo json_encode(['success' => false, 'error' => 'Database error: ' . mysqli_error($conn)]);
        } else {
            echo json_encode(['success' => true]);
        }
        exit;
    }

    // =====================================================
  // SINGLE ACCOUNT FETCH FOR EDIT MODAL (JSON, GET)
  // =====================================================
  if (isset($_GET['fetch'], $_GET['id']) && in_array($_GET['fetch'], ['admin', 'agent', 'user'], true)) {
      header('Content-Type: application/json');

      $id   = (int) $_GET['id'];
      $type = $_GET['fetch'];

      if ($type === 'admin') {
        // FIXED: Removed extra columns not in DB
        $sql = "SELECT id, first_name, middle_name, last_name, username, email, phone, address, created_at, photo_path FROM admin_accounts WHERE id = ?";
      } elseif ($type === 'agent') {
        // FIXED: Removed extra columns not in DB
        $sql = "SELECT id, first_name, middle_name, last_name, username, email, phone, address, created_at, photo_path FROM agent_accounts WHERE id = ?";
      } else { // user
        $userPhoneColumn = columnExists($conn, 'user_accounts', 'phone_number')
          ? 'phone_number'
          : (columnExists($conn, 'user_accounts', 'mobile_number') ? 'mobile_number' : null);
        $userHasPhotoColumn = columnExists($conn, 'user_accounts', 'photo_path');

        if ($userPhoneColumn === null) {
          echo json_encode(['error' => 'No phone column found in user_accounts (expected phone_number or mobile_number).']);
          exit;
        }

        $photoSelect = $userHasPhotoColumn ? ', photo_path' : '';
        $sql = "SELECT id, first_name, middle_name, last_name, email, $userPhoneColumn AS mobile_number, address, created_at$photoSelect FROM user_accounts WHERE id = ?";
      }

      $stmt = $conn->prepare($sql);
      if (!$stmt) {
        echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
        exit;
      }

      $stmt->bind_param("i", $id);
      $stmt->execute();
      $result  = $stmt->get_result();
      $account = $result->fetch_assoc();
      $stmt->close();

      if (!$account) {
        echo json_encode(['error' => 'Account not found', 'id' => $id]);
      } else {
        echo json_encode($account);
      }
      exit; 
  }

      // POST: Delete location
      if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['action']) && $_POST['action'] === 'delete_location') {
        $location_id = intval($_POST['location_id'] ?? 0);

        if (!$location_id) {
          header('Content-Type: application/json');
          echo json_encode(['success' => false, 'error' => 'Invalid location selected']);
          exit;
        }

        $lotsCountQuery = "SELECT COUNT(*) AS total FROM lots WHERE location_id = $location_id";
        $lotsCountRes = mysqli_query($conn, $lotsCountQuery);
        $lotsCountRow = $lotsCountRes ? mysqli_fetch_assoc($lotsCountRes) : ['total' => 0];
        $lotsCount = (int)($lotsCountRow['total'] ?? 0);

        if ($lotsCount > 0) {
          header('Content-Type: application/json');
          echo json_encode([
            'success' => false,
            'error' => 'Cannot delete location with existing lots. Delete or move the lots first.'
          ]);
          exit;
        }

        $deletedBlueprintRows = 0;
        $deletedBlueprintFiles = 0;

        if (tableExists($conn, 'blueprints')) {
          $blueprintRowsRes = mysqli_query($conn, "SELECT id, filename FROM blueprints WHERE location_id = $location_id");
          if ($blueprintRowsRes) {
            while ($bp = mysqli_fetch_assoc($blueprintRowsRes)) {
              $filename = trim((string)($bp['filename'] ?? ''));
              if ($filename !== '') {
                // Use basename to avoid deleting files outside the intended folder.
                $safeName = basename($filename);
                $filePath = 'blueprints/' . $safeName;
                if (is_file($filePath) && @unlink($filePath)) {
                  $deletedBlueprintFiles++;
                }
              }
            }
          }

          $deleteBlueprintRowsQuery = "DELETE FROM blueprints WHERE location_id = $location_id";
          $deletedBlueprintRowsRes = mysqli_query($conn, $deleteBlueprintRowsQuery);
          if ($deletedBlueprintRowsRes === false) {
            header('Content-Type: application/json');
            echo json_encode([
              'success' => false,
              'error' => 'Failed to remove blueprint records: ' . mysqli_error($conn)
            ]);
            exit;
          }
          $deletedBlueprintRows = (int)mysqli_affected_rows($conn);
        }

        $deleteLocationQuery = "DELETE FROM lot_locations WHERE id = $location_id";
        $success = mysqli_query($conn, $deleteLocationQuery);

        header('Content-Type: application/json');
        echo json_encode([
          'success' => (bool)$success,
          'message' => $success
            ? ('Location deleted successfully. Removed ' . $deletedBlueprintRows . ' blueprint record(s) and ' . $deletedBlueprintFiles . ' file(s).')
            : mysqli_error($conn)
        ]);
        exit;
      }

  // =====================================================
  // ADMIN ACCOUNT CRUD  (AJAX: account_action)
  // =====================================================
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['account_action'])) {
      // Prevent PHP errors from breaking JSON
      ini_set('display_errors', 0);
      ob_start();
      registerJsonFatalHandler();
      set_error_handler(function($errno, $errstr, $errfile, $errline) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => "PHP error [$errno]: $errstr in $errfile:$errline"]);
        exit;
      });
      header('Content-Type: application/json');
      $accountAction = strtolower(trim((string)($_POST['account_action'] ?? '')));

      // ---------- ADD ADMIN ----------
      if ($accountAction === 'add') {
          $first_name        = mysqli_real_escape_string($conn, $_POST['first_name']         ?? '');
          $middle_name       = mysqli_real_escape_string($conn, $_POST['middle_name']        ?? '');
          $last_name         = mysqli_real_escape_string($conn, $_POST['last_name']          ?? '');
          $email             = mysqli_real_escape_string($conn, $_POST['email']              ?? '');
          $username          = mysqli_real_escape_string($conn, $_POST['username']           ?? '');
          $phone             = mysqli_real_escape_string($conn, $_POST['phone']              ?? '');
          $address           = mysqli_real_escape_string($conn, $_POST['address']            ?? '');
          $availability      = isset($_POST['availability']) ? 1 : 0;

          // password (required)
          $raw_password = $_POST['password'] ?? '';
          if ($raw_password === '') {
              echo json_encode(['success' => false, 'error' => 'Password is required.']);
              exit;
          }
          $password = password_hash($raw_password, PASSWORD_DEFAULT);

          // optional photo
          $photo_path = null;
          if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
              $photo_path = handleFileUpload($_FILES['photo']);
          }

          // FIXED: Removed columns that don't exist in admin_accounts table
          $sql = "INSERT INTO admin_accounts 
                  (first_name, middle_name, last_name, email, username, password,
                  phone, address, photo_path, availability)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
          $stmt = $conn->prepare($sql);

          if (!$stmt) {
              echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
              exit;
          }

          // 9 strings + 1 int = "sssssssssi"
          $stmt->bind_param(
              "sssssssssi",
              $first_name, $middle_name, $last_name, $email, $username, $password,
              $phone, $address, $photo_path, $availability
          );

          $ok = $stmt->execute();

          echo json_encode([
              'success' => $ok,
              'message' => $ok ? 'Admin account created successfully!'
                              : 'Error creating admin account: ' . $stmt->error
          ]);
          $stmt->close();
          exit;
      }

      // ---------- UPDATE ADMIN ----------
      if ($accountAction === 'update') {
          $account_id       = intval($_POST['account_id'] ?? 0);
          $first_name       = mysqli_real_escape_string($conn, $_POST['first_name']         ?? '');
          $middle_name      = mysqli_real_escape_string($conn, $_POST['middle_name']        ?? '');
          $last_name        = mysqli_real_escape_string($conn, $_POST['last_name']          ?? '');
          $email            = mysqli_real_escape_string($conn, $_POST['email']              ?? '');
          $username         = mysqli_real_escape_string($conn, $_POST['username']           ?? '');
          $phone            = mysqli_real_escape_string($conn, $_POST['phone']              ?? '');
          $address          = mysqli_real_escape_string($conn, $_POST['address']            ?? '');
          $availability     = isset($_POST['availability']) ? 1 : 0;

          $photo_path = null;
          if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
              $photo_path = handleFileUpload($_FILES['photo']);
          }

          // FIXED: Removed non-existent columns from UPDATE logic
          if (!empty($_POST['password'])) {
              $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

              $sql = "UPDATE admin_accounts 
                      SET first_name=?, middle_name=?, last_name=?, email=?, username=?, 
                          password=?, phone=?, address=?, photo_path=?, availability=?
                      WHERE id=?";
              $stmt = $conn->prepare($sql);
              if (!$stmt) {
                  echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
                  exit;
              }

              // "sssssssssii"
              $stmt->bind_param(
                  "sssssssssii",
                  $first_name, $middle_name, $last_name, $email, $username,
                  $password, $phone, $address, $photo_path, $availability, $account_id
              );

          } else {
              // no password change
              $sql = "UPDATE admin_accounts 
                      SET first_name=?, middle_name=?, last_name=?, email=?, username=?,
                          phone=?, address=?, photo_path=?, availability=?
                      WHERE id=?";
              $stmt = $conn->prepare($sql);
              if (!$stmt) {
                  echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
                  exit;
              }

                // "ssssssssii"
              $stmt->bind_param(
                  "ssssssssii",
                  $first_name, $middle_name, $last_name, $email, $username,
                  $phone, $address, $photo_path, $availability, $account_id
              );
          }

          $ok = $stmt->execute();

          echo json_encode([
              'success' => $ok,
              'message' => $ok ? 'Account updated successfully!'
                              : 'Error updating account: ' . $stmt->error
          ]);
          $stmt->close();
          exit;
      }

      // ---------- DELETE ADMIN ----------
      if ($accountAction === 'delete') {
          $account_id = intval($_POST['account_id'] ?? 0);

          $sql  = "DELETE FROM admin_accounts WHERE id = ?";
          $stmt = $conn->prepare($sql);
          if (!$stmt) {
              echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
              exit;
          }
          $stmt->bind_param("i", $account_id);
          $ok = $stmt->execute();

          echo json_encode([
              'success' => $ok,
              'message' => $ok ? 'Account deleted successfully!'
                              : 'Error deleting account: ' . $stmt->error
          ]);
          $stmt->close();
          exit;
      }

          // Fallback: always return JSON if nothing matched
          echo json_encode(['success' => false, 'error' => 'Unknown admin account action or missing fields.']);
          exit;
  }



  // =====================================================
  // AGENT ACCOUNT CRUD (agent_action)
  //    – add/update: JSON (AJAX)
  //    – delete: normal form, no JSON
  // =====================================================
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agent_action'])) {
      ini_set('display_errors', 0);
      ob_start();
      registerJsonFatalHandler();
      set_error_handler(function($errno, $errstr, $errfile, $errline) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => "PHP error [$errno]: $errstr in $errfile:$errline"]);
        exit;
      });

      $action = strtolower(trim((string)($_POST['agent_action'] ?? '')));

      // Only ADD & UPDATE should respond with JSON (AJAX)
      if ($action === 'add' || $action === 'update') {
          header('Content-Type: application/json');
      }

      // ----------------- ADD AGENT (AJAX) -----------------
      if ($action === 'add') {
          $first_name        = mysqli_real_escape_string($conn, $_POST['first_name']);
          $middle_name       = mysqli_real_escape_string($conn, $_POST['middle_name']);
          $last_name         = mysqli_real_escape_string($conn, $_POST['last_name']);
          $username          = mysqli_real_escape_string($conn, $_POST['username']);
          $email             = mysqli_real_escape_string($conn, $_POST['email']);
          $phone             = mysqli_real_escape_string($conn, $_POST['phone']);
          $address           = mysqli_real_escape_string($conn, $_POST['address']);
          
          // Removed short_description and years_experience to prevent crash
          $availability      = isset($_POST['availability']) ? 1 : 0;
          
          $password          = password_hash($_POST['password'], PASSWORD_DEFAULT);

          $photo_path = null;
          if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
              $photo_path = handleFileUpload($_FILES['photo']);
          }

          // NOTE: years_experience and short_description removed
          $sql = "INSERT INTO agent_accounts 
            (first_name, middle_name, last_name, username, email, phone, address, 
            photo_path, availability, password, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')";

          $stmt = $conn->prepare($sql);
          if (!$stmt) {
              echo json_encode(['success' => false, 'error' => "Prepare failed: " . $conn->error]);
              exit;
          }

          // 8 strings + 1 int + 1 string
          $stmt->bind_param(
            "ssssssssis",
            $first_name, $middle_name, $last_name, $username, $email, $phone,
            $address, $photo_path, $availability, $password
          );

          try {
            $ok = $stmt->execute();
            echo json_encode([
                'success' => $ok,
                'message' => $ok ? "Agent account created successfully!"
                                : "Error creating agent account: " . $stmt->error
            ]);
          } catch (Throwable $e) {
            $rawError = $e->getMessage();
            $friendlyError = $rawError;

            if (stripos($rawError, 'Duplicate entry') !== false && stripos($rawError, 'username') !== false) {
              $friendlyError = 'Username already exists. Please choose a different username.';
            }

            echo json_encode([
              'success' => false,
              'error' => $friendlyError
            ]);
          }
          $stmt->close();
          exit;
      }

      // ----------------- UPDATE AGENT (AJAX) -----------------
        if ($action === 'update') {
          $user_id       = intval($_POST['account_id'] ?? 0);
          $first_name    = mysqli_real_escape_string($conn, $_POST['first_name'] ?? '');
          $middle_name   = mysqli_real_escape_string($conn, $_POST['middle_name'] ?? '');
          $username      = mysqli_real_escape_string($conn, $_POST['username'] ?? '');
          $last_name     = mysqli_real_escape_string($conn, $_POST['last_name'] ?? '');
          $email         = mysqli_real_escape_string($conn, $_POST['email'] ?? '');
          $phone_number  = mysqli_real_escape_string($conn, $_POST['phone_number'] ?? ($_POST['mobile_number'] ?? ''));
          $address       = mysqli_real_escape_string($conn, $_POST['address'] ?? '');
              $photo_path = handleFileUpload($_FILES['photo']);
          }

          if (!empty($_POST['password'])) {
              $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

              $sql = "UPDATE agent_accounts 
                  SET first_name=?, middle_name=?, last_name=?, username=?, email=?, phone=?, 
                    address=?, availability=?, password=?, photo_path=? 
                  WHERE id=?";
              $stmt = $conn->prepare($sql);
              if (!$stmt) {
                  echo json_encode(['success' => false, 'error' => "Prepare failed: " . $conn->error]);
                  exit;
              }

              $stmt->bind_param(
                "sssssssissi",
                $first_name, $middle_name, $last_name, $username, $email, $phone,
                $address, $availability, $password, $photo_path, $agent_id
              );
          } else {
              $sql = "UPDATE agent_accounts 
                  SET first_name=?, middle_name=?, last_name=?, username=?, email=?, phone=?, 
                    address=?, availability=?, photo_path=? 
                  WHERE id=?";
              $stmt = $conn->prepare($sql);
              if (!$stmt) {
                  echo json_encode(['success' => false, 'error' => "Prepare failed: " . $conn->error]);
                  exit;
              }

              $stmt->bind_param(
                "sssssssisi",
                $first_name, $middle_name, $last_name, $username, $email, $phone,
                $address, $availability, $photo_path, $agent_id
              );
          }

          $ok = $stmt->execute();
          echo json_encode([
              'success' => $ok,
              'message' => $ok ? "Agent account updated successfully!"
                              : "Error updating agent account: " . $stmt->error
          ]);
          $stmt->close();
          exit;
      }

      // ----------------- DELETE AGENT (NORMAL FORM) -----------------
      if ($action === 'delete') {
          $agent_id = intval($_POST['agent_id']);
          $sql      = "DELETE FROM agent_accounts WHERE id = ?";
          $stmt     = $conn->prepare($sql);

          if (!$stmt) {
            $_SESSION['error_message'] = "Prepare failed: " . $conn->error;
            header('Location: admindashboard.php#admin-accounts');
            exit;
          } else {
            $stmt->bind_param("i", $agent_id);
            if ($stmt->execute()) {
              $_SESSION['success_message'] = "Agent account deleted successfully!";
              header('Location: admindashboard.php#admin-accounts');
              exit;
            } else {
              $_SESSION['error_message'] = "Error deleting agent account: " . $conn->error;
              $stmt->close();
              header('Location: admindashboard.php#admin-accounts');
              exit;
            }
            $stmt->close();
          }
      }



  // =====================================================
  // USER ACCOUNT CRUD (AJAX: user_action)
  // =====================================================
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_action'])) {
      ini_set('display_errors', 0);
      ob_start();
      registerJsonFatalHandler();
      set_error_handler(function($errno, $errstr, $errfile, $errline) {
          header('Content-Type: application/json');
          echo json_encode(['success' => false, 'error' => "PHP error [$errno]: $errstr in $errfile:$errline"]);
          exit;
      });
      header('Content-Type: application/json');
      $userAction = strtolower(trim((string)($_POST['user_action'] ?? '')));
      $userPhoneColumn = columnExists($conn, 'user_accounts', 'phone_number')
        ? 'phone_number'
        : (columnExists($conn, 'user_accounts', 'mobile_number') ? 'mobile_number' : null);
      $userHasPhotoColumn = columnExists($conn, 'user_accounts', 'photo_path');
      $userHasAccountNumberColumn = columnExists($conn, 'user_accounts', 'account_number');

      if ($userPhoneColumn === null) {
        echo json_encode(['success' => false, 'error' => 'No phone column found in user_accounts (expected phone_number or mobile_number).']);
        exit;
      }

      // ---------- ADD USER ----------
      if ($userAction === 'add') {
        $first_name   = mysqli_real_escape_string($conn, $_POST['first_name'] ?? '');
        $middle_name  = mysqli_real_escape_string($conn, $_POST['middle_name'] ?? '');
        $username     = mysqli_real_escape_string($conn, $_POST['username'] ?? '');
        $last_name    = mysqli_real_escape_string($conn, $_POST['last_name'] ?? '');
        $email        = mysqli_real_escape_string($conn, $_POST['email'] ?? '');
        $phone_number = mysqli_real_escape_string($conn, $_POST['phone_number'] ?? ($_POST['mobile_number'] ?? ''));
        $address      = mysqli_real_escape_string($conn, $_POST['address'] ?? '');
        $passwordRaw  = (string)($_POST['password'] ?? '');
        $password     = password_hash($passwordRaw, PASSWORD_DEFAULT);

          $photo_path = null;
          if ($userHasPhotoColumn && isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
              $photo_path = handleFileUpload($_FILES['photo']);
          }

          $insertColumns = ['first_name', 'middle_name', 'username', 'last_name', 'email', $userPhoneColumn, 'address', 'password'];
          $insertValues = [$first_name, $middle_name, $username, $last_name, $email, $phone_number, $address, $password];
          $insertTypes = 'ssssssss';

          if ($userHasPhotoColumn) {
            $insertColumns[] = 'photo_path';
            $insertValues[] = $photo_path;
            $insertTypes .= 's';
          }

          if ($userHasAccountNumberColumn) {
            $accountNumber = trim((string)($_POST['account_number'] ?? ''));
            if ($accountNumber === '') {
              $accountNumber = generateUniqueUserAccountNumber($conn);
            } else {
              $dupStmt = $conn->prepare("SELECT id FROM user_accounts WHERE account_number = ? LIMIT 1");
              if ($dupStmt) {
                $dupStmt->bind_param('s', $accountNumber);
                $dupStmt->execute();
                $dupRes = $dupStmt->get_result();
                if ($dupRes && $dupRes->fetch_assoc()) {
                  $dupStmt->close();
                  echo json_encode(['success' => false, 'error' => 'Account number already exists.']);
                  exit;
                }
                $dupStmt->close();
              }
            }

            $insertColumns[] = 'account_number';
            $insertValues[] = $accountNumber;
            $insertTypes .= 's';
          }

          $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
          $sql  = "INSERT INTO user_accounts (" . implode(', ', $insertColumns) . ") VALUES ($placeholders)";
          $stmt = $conn->prepare($sql);

          if (!$stmt) {
              echo json_encode(['success' => false, 'error' => "Prepare failed: " . $conn->error]);
              exit;
          }

            $stmt->bind_param($insertTypes, ...$insertValues);

          $ok = $stmt->execute();
          echo json_encode([
              'success' => $ok,
              'message' => $ok ? "User account created successfully!" :
                                "Error creating user account: " . $stmt->error
          ]);
          $stmt->close();
          exit;
      }

      // ---------- UPDATE USER ----------
        if ($userAction === 'update') {
          $user_id       = intval($_POST['account_id'] ?? 0);
          $first_name    = mysqli_real_escape_string($conn, $_POST['first_name'] ?? '');
          $middle_name   = mysqli_real_escape_string($conn, $_POST['middle_name'] ?? '');
          $last_name     = mysqli_real_escape_string($conn, $_POST['last_name'] ?? '');
          $email         = mysqli_real_escape_string($conn, $_POST['email'] ?? '');
          $phone_number  = mysqli_real_escape_string($conn, $_POST['phone_number'] ?? ($_POST['mobile_number'] ?? ''));
          $address       = mysqli_real_escape_string($conn, $_POST['address'] ?? '');

          if ($user_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Missing or invalid user ID.']);
            exit;
          }

          $photo_path   = null;
          $passwordHash = null;

          $update_fields = [
                'first_name=?',
                'middle_name=?',
                'username=?',
                'last_name=?',
                'email=?',
                $userPhoneColumn . '=?',
                'address=?'
              ];
              $bind_types  = "sssssss";
              $bind_values = [$first_name, $middle_name, $username, $last_name, $email, $phone_number, $address];

            if ($userHasPhotoColumn && isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
              $photo_path = handleFileUpload($_FILES['photo']);
              if ($photo_path !== null) {
                  $update_fields[] = 'photo_path=?';
                  $bind_types     .= 's';
                  $bind_values[]   = $photo_path;
              }
          }

          if (!empty($_POST['password'])) {
              $passwordHash    = password_hash($_POST['password'], PASSWORD_DEFAULT);
              $update_fields[] = 'password=?';
              $bind_types     .= 's';
              $bind_values[]   = $passwordHash;
          }

          $sql = "UPDATE user_accounts SET " . implode(', ', $update_fields) . " WHERE id=?";
          $bind_types  .= 'i';
          $bind_values[] = $user_id;

          $stmt = $conn->prepare($sql);
          if (!$stmt) {
              echo json_encode(['success' => false, 'error' => "Prepare failed: " . $conn->error]);
              exit;
          }

          $stmt->bind_param($bind_types, ...$bind_values);

          $ok = $stmt->execute();
          echo json_encode([
              'success' => $ok,
              'message' => $ok ? "User account updated successfully!" :
                                "Error updating user account: " . $stmt->error
          ]);
          $stmt->close();
          exit;
      }

      // ---------- DELETE USER ----------
        if ($userAction === 'delete') {
          $user_id = intval($_POST['user_id'] ?? 0);
          if ($user_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Missing or invalid user ID.']);
            exit;
          }
          $sql     = "DELETE FROM user_accounts WHERE id = ?";
          $stmt    = $conn->prepare($sql);
          if (!$stmt) {
              echo json_encode(['success' => false, 'error' => "Prepare failed: " . $conn->error]);
              exit;
          }
          $stmt->bind_param("i", $user_id);

          $ok = $stmt->execute();
          echo json_encode([
              'success' => $ok,
              'message' => $ok ? "User account deleted successfully!" :
                                "Error deleting user account: " . $conn->error
          ]);
          $stmt->close();
          exit;
      }

          // Fallback: always return JSON if nothing matched
          echo json_encode(['success' => false, 'error' => 'Unknown user account action or missing fields.']);
          exit;
  }

  // =============================================
  // GENERIC GET FETCH: lots / locations (AJAX)
  // =============================================
  if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch'])) {
      if ($_GET['fetch'] === 'lots') {
          $location_id = isset($_GET['location_id']) ? intval($_GET['location_id']) : 0;

          if ($location_id > 0) {
              $lotsQuery = "SELECT lots.*, lot_locations.location_name
                            FROM lots
                            LEFT JOIN lot_locations ON lots.location_id = lot_locations.id
                            WHERE lots.location_id = $location_id
                            ORDER BY lots.id DESC";
          } else {
          header('Content-Type: application/json');
          echo json_encode([]);
          exit;
          }

          $lotsResult = mysqli_query($conn, $lotsQuery);
          $lots        = [];
          if ($lotsResult) {
              while ($lot = mysqli_fetch_assoc($lotsResult)) {
                $lot['status'] = normalizeLotStatus($lot['status'] ?? 'Available');
                $lot['workflow_stage'] = deriveLotWorkflowStage($lot['status'] ?? 'Available', $lot['payment_type'] ?? '');
                  $lots[] = $lot;
              }
          }

          header('Content-Type: application/json');
          echo json_encode($lots);
          exit;
      }

      if ($_GET['fetch'] === 'locations') {
          $locationsQuery  = "SELECT id, location_name FROM lot_locations";
          $locationsResult = mysqli_query($conn, $locationsQuery);
          $locations       = [];
          if ($locationsResult) {
              while ($row = mysqli_fetch_assoc($locationsResult)) {
                  $locations[] = $row;
              }
          }

          header('Content-Type: application/json');
          echo json_encode($locations);
          exit;
      }

      if ($_GET['fetch'] === 'surrendered_lots') {
          $rows = [];
          $check = $conn->query("SHOW TABLES LIKE 'lot_status_history'");
          if ($check && $check->num_rows > 0) {
              $sql = "SELECT h.id, h.lot_id, h.previous_owner_name, h.previous_owner_email, h.paid_amount, h.refund_amount, h.company_amount, h.remarks, h.event_date, l.block_number, l.lot_number, COALESCE(ll.location_name, '') AS location_name FROM lot_status_history h LEFT JOIN lots l ON l.id = h.lot_id LEFT JOIN lot_locations ll ON ll.id = l.location_id WHERE h.event_type = 'surrender' ORDER BY h.event_date DESC LIMIT 300";
              $result = $conn->query($sql);
              if ($result) {
                  while ($row = $result->fetch_assoc()) {
                      $rows[] = $row;
                  }
              }
          }

          header('Content-Type: application/json');
          echo json_encode($rows);
          exit;
      }
  }



  // =============================================
  // VIEWINGS: REQUEST & ASSIGN
  // =============================================

  // Request a viewing (public form)
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['viewing_action']) && $_POST['viewing_action'] === 'request') {
      $user_id          = $_SESSION['user_id'] ?? null;
      $agent_id         = null;
      $client_first_name= mysqli_real_escape_string($conn, $_POST['client_first_name']);
      $client_last_name = mysqli_real_escape_string($conn, $_POST['client_last_name']);
      $client_email     = mysqli_real_escape_string($conn, $_POST['client_email']);
      $client_phone     = mysqli_real_escape_string($conn, $_POST['client_phone']);
      $lot_id           = mysqli_real_escape_string($conn, $_POST['lot_id']);
      $preferred_date   = mysqli_real_escape_string($conn, $_POST['preferred_date']);
      $status           = 'requested';
      $client_lat       = mysqli_real_escape_string($conn, $_POST['client_lat']);
      $client_lng       = mysqli_real_escape_string($conn, $_POST['client_lng']);
      $location_id      = mysqli_real_escape_string($conn, $_POST['location_id']);

      $insertQuery = "INSERT INTO viewings (
                          agent_id, user_id, client_first_name, client_last_name, client_email, client_phone,
                          lot_no, preferred_at, status, client_lat, client_lng, location_id, lot_id, created_at
                      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

      $stmt = $conn->prepare($insertQuery);
      $stmt->bind_param(
          "iissssissssss",
          $agent_id, $user_id, $client_first_name, $client_last_name,
          $client_email, $client_phone, $lot_id, $preferred_date,
          $status, $client_lat, $client_lng, $location_id, $lot_id
      );

      if ($stmt->execute()) {
          $success_message = "Viewing request submitted successfully!";
      } else {
          $error_message = "Error submitting request: " . $stmt->error;
      }
      $stmt->close();
  }

  // Assign agent to viewing (admin)
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['viewing_action']) && $_POST['viewing_action'] === 'assign_agent') {
      $viewingId = intval($_POST['viewing_id']);
      $agentId   = intval($_POST['agent_id']);

      $sql  = "UPDATE viewings SET agent_id = ?, status = 'scheduled' WHERE id = ?";
      $stmt = $conn->prepare($sql);
      if ($stmt) {
          $stmt->bind_param("ii", $agentId, $viewingId);
          if ($stmt->execute()) {
              $success_message = "Agent assigned successfully!";
          } else {
              $error_message = "Failed to assign agent.";
          }
          $stmt->close();
      }
      header("Location: " . $_SERVER['PHP_SELF'] . "#viewings");
      exit;
  }

  // =============================================
  // FETCH VIEWINGS & ACTIVE AGENTS FOR UI
  // =============================================

  // Viewing list
  $all_viewings  = [];
  // Only fetch active viewings for the default list, or modify to fetch all
  $viewingsQuery = "
      SELECT 
          v.*,
          ll.location_name,
          l.block_number,
          l.lot_number,
          l.lot_size,
          l.lot_price
      FROM viewings v
      LEFT JOIN lots l 
          ON (v.lot_id IS NOT NULL AND l.id = v.lot_id) 
          OR (v.lot_id IS NULL AND l.lot_number = v.lot_no)
      LEFT JOIN lot_locations ll 
          ON ll.id = l.location_id
      ORDER BY v.created_at DESC
      LIMIT 10
  ";

  $viewingsResult = mysqli_query($conn, $viewingsQuery);
  if ($viewingsResult) {
      while ($viewing = mysqli_fetch_assoc($viewingsResult)) {
          $all_viewings[] = $viewing;
      }
  }

  // Active agents for assignment dropdown
  $agents      = [];
  $agentsQuery = "SELECT id, first_name, last_name FROM agent_accounts WHERE status = 'active'";
  $agentsResult = mysqli_query($conn, $agentsQuery);
  if ($agentsResult) {
      while ($agent = mysqli_fetch_assoc($agentsResult)) {
          $agents[] = $agent;
      }
  }

  // =============================================
  // SINGLE FETCH BLOCK FOR ACCOUNTS (ADMIN UI)
  // =============================================

  // Admin accounts
  $adminAccounts   = [];
  // FIXED: Removed missing columns from SELECT
  $accountsQuery   = "SELECT id, first_name, middle_name, last_name, username, email, phone, address, photo_path, availability, created_at FROM admin_accounts ORDER BY created_at DESC";
  $accountsResult  = mysqli_query($conn, $accountsQuery);
  if (!$accountsResult) {
    die('Admin Accounts Query Error: ' . mysqli_error($conn));
  }
  while ($account = mysqli_fetch_assoc($accountsResult)) {
    $adminAccounts[] = $account;
  }

  // Agent accounts
  $agentAccounts  = [];
  // FIXED: Removed missing columns from SELECT
  $agentQuery     = "
      SELECT 
          id,
          first_name,
          middle_name,
          last_name,
          username,
          email,
          phone,
          address,
          availability,
          status,
          created_at
      FROM agent_accounts
      ORDER BY created_at DESC
  ";

  $agentResult = mysqli_query($conn, $agentQuery);

  if (!$agentResult) {
      die("Agent Query Error: " . mysqli_error($conn));
  }

  while ($agent = mysqli_fetch_assoc($agentResult)) {
      $agentAccounts[] = $agent;
  }


  // User accounts
  $userAccounts = [];
  $userQuery    = "SELECT id, first_name, middle_name, last_name, email, mobile_number, address, created_at FROM user_accounts ORDER BY created_at DESC LIMIT 5";
  $userResult   = mysqli_query($conn, $userQuery);
  if ($userResult) {
      while ($user = mysqli_fetch_assoc($userResult)) {
          $userAccounts[] = $user;
      }
  }


  // Handle file uploads
  function handleFileUpload($file, $uploadDir = 'uploads/profiles/') {
      if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
          return null;
      }
      // Create upload directory if it doesn't exist
      if (!file_exists($uploadDir)) {
          mkdir($uploadDir, 0777, true);
      }
      $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
      if (!in_array($file['type'], $allowedTypes)) {
          return null;
      }
      $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
      $filename = uniqid('profile_') . '.' . $fileExtension;
      $uploadPath = $uploadDir . $filename;
      if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
          return $uploadPath;
      }
      return null;
  }

    $dashboard_stats['pending_documents'] = getPendingDocumentsCount($conn);

    $dashboard_stats['total_sales'] = 0;
    if ($salesAmountExprRoot) {
      $salesTotalQuery = "SELECT IFNULL(SUM($salesAmountExprRoot), 0) AS total FROM sales";
      $salesTotalResult = mysqli_query($conn, $salesTotalQuery);
      if ($salesTotalResult) {
        $salesTotalRow = mysqli_fetch_assoc($salesTotalResult);
        $dashboard_stats['total_sales'] = (float)($salesTotalRow['total'] ?? 0);
      }
    }

    function buildMonthlySalesTrend($conn, ?string $date_from, ?string $date_to, ?int $location_id, ?string $salesDateCol, ?string $salesLocationCol, ?string $salesAmountExprRoot, string $salesPeriod = 'monthly'): array {
      $monthlySales = [];
      $salesPeriod = strtolower(trim($salesPeriod));
      $allowedSalesPeriods = ['daily', 'weekly', 'monthly', 'yearly'];
      if (!in_array($salesPeriod, $allowedSalesPeriods, true)) {
        $salesPeriod = 'monthly';
      }

      $periodConfig = [
        'daily' => [
          'default_where' => 'DAY',
          'label_expr' => "DATE_FORMAT(DATE({date_col}), '%b %d')",
          'group_expr' => 'DATE({date_col}) AS period_key',
          'group_by' => 'DATE({date_col})',
          'order_by' => 'DATE({date_col})',
          'title' => 'Daily Sales Trend',
          'window' => 'Last 30 Days',
        ],
        'weekly' => [
          'default_where' => 'WEEK',
          'label_expr' => "DATE_FORMAT(DATE_SUB(DATE({date_col}), INTERVAL WEEKDAY({date_col}) DAY), '%b %d')",
          'group_expr' => "DATE_SUB(DATE({date_col}), INTERVAL WEEKDAY({date_col}) DAY) AS period_key",
          'group_by' => 'YEARWEEK({date_col}, 1)',
          'order_by' => 'YEARWEEK({date_col}, 1)',
          'title' => 'Weekly Sales Trend',
          'window' => 'Last 12 Weeks',
        ],
        'monthly' => [
          'default_where' => 'MONTH',
          'label_expr' => "DATE_FORMAT(DATE({date_col}), '%b %Y')",
          'group_expr' => "DATE_FORMAT({date_col}, '%Y-%m-01') AS period_key",
          'group_by' => 'YEAR({date_col}), MONTH({date_col})',
          'order_by' => 'YEAR({date_col}) ASC, MONTH({date_col}) ASC',
          'title' => 'Monthly Sales Trend',
          'window' => 'Last 12 Months',
        ],
        'yearly' => [
          'default_where' => 'YEAR',
          'label_expr' => "DATE_FORMAT(DATE({date_col}), '%Y')",
          'group_expr' => "STR_TO_DATE(CONCAT(YEAR({date_col}), '-01-01'), '%Y-%m-%d') AS period_key",
          'group_by' => 'YEAR({date_col})',
          'order_by' => 'YEAR({date_col}) ASC',
          'title' => 'Yearly Sales Trend',
          'window' => 'Last 5 Years',
        ],
      ];

      $period = $periodConfig[$salesPeriod];

      $buildTrendRows = function(string $sourceTable, string $dateCol, string $amountExpr, array $whereParts) use ($conn, $period, $salesPeriod): array {
        $rows = [];
        $groupExpr = str_replace('{date_col}', $dateCol, $period['group_expr']);
        $groupBy = str_replace('{date_col}', $dateCol, $period['group_by']);
        $orderBy = str_replace('{date_col}', $dateCol, $period['order_by']);
        $labelExpr = str_replace('{date_col}', $dateCol, $period['label_expr']);

        $sql = "
          SELECT {$groupExpr}, {$labelExpr} AS period_label, IFNULL(SUM({$amountExpr}), 0) AS total
          FROM {$sourceTable}
          WHERE 1
        ";
        if (!empty($whereParts)) {
          $sql .= ' AND ' . implode(' AND ', $whereParts);
        }
        $sql .= " GROUP BY {$groupBy}
                 ORDER BY {$orderBy}";

        $res = mysqli_query($conn, $sql);
        if ($res) {
          while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = [
              'period' => (string)($row['period_label'] ?? ''),
              'amount' => (float)($row['total'] ?? 0),
              'sales_period' => $salesPeriod,
            ];
          }
        }

        return $rows;
      };

      // Primary source: recorded payment transactions (actual collected amounts).
      if (tableExists($conn, 'lot_payment_transactions') && tableExists($conn, 'lots')) {
        $paymentWhere = [];
        if ($date_from) {
          $paymentWhere[] = "t.payment_date >= '" . mysqli_real_escape_string($conn, $date_from) . " 00:00:00'";
        }
        if ($date_to) {
          $paymentWhere[] = "t.payment_date < DATE_ADD('" . mysqli_real_escape_string($conn, $date_to) . "', INTERVAL 1 DAY)";
        }
        if ($location_id) {
          $paymentWhere[] = "l.location_id = " . (int)$location_id;
        }
        if (!$date_from && !$date_to) {
          if ($salesPeriod === 'daily') {
            $paymentWhere[] = "t.payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
          } elseif ($salesPeriod === 'weekly') {
            $paymentWhere[] = "t.payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK)";
          } elseif ($salesPeriod === 'yearly') {
            $paymentWhere[] = "t.payment_date >= DATE_SUB(CURDATE(), INTERVAL 5 YEAR)";
          } else {
            $paymentWhere[] = "t.payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)";
          }
        }

        $monthlySales = $buildTrendRows('lot_payment_transactions t INNER JOIN lots l ON l.id = t.lot_id', 't.payment_date', 't.amount', $paymentWhere);
      }

      // Fallback to sales table if payment ledger has no result or is unavailable.
      if (empty($monthlySales) && $salesAmountExprRoot && $salesDateCol) {
        $salesWhere = [];
        if ($date_from) {
          $salesWhere[] = "$salesDateCol >= '" . mysqli_real_escape_string($conn, $date_from) . " 00:00:00'";
        }
        if ($date_to) {
          $salesWhere[] = "$salesDateCol < DATE_ADD('" . mysqli_real_escape_string($conn, $date_to) . "', INTERVAL 1 DAY)";
        }
        if ($salesLocationCol && $location_id) {
          $salesWhere[] = "$salesLocationCol = " . (int)$location_id;
        }
        if (!$date_from && !$date_to) {
          if ($salesPeriod === 'daily') {
            $salesWhere[] = "$salesDateCol >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
          } elseif ($salesPeriod === 'weekly') {
            $salesWhere[] = "$salesDateCol >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK)";
          } elseif ($salesPeriod === 'yearly') {
            $salesWhere[] = "$salesDateCol >= DATE_SUB(CURDATE(), INTERVAL 5 YEAR)";
          } else {
            $salesWhere[] = "$salesDateCol >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)";
          }
        }

        $monthlySales = $buildTrendRows('sales', $salesDateCol, $salesAmountExprRoot, $salesWhere);
      }

      return $monthlySales;
    }

    function buildInstallmentDueOverview($conn, ?int $location_id = null): array {
      $stats = [
        'overdue_installments' => 0,
        'due_this_week' => 0,
        'missed_this_month' => 0,
        'expected_this_month' => 0.0,
        'collected_this_month' => 0.0,
      ];

      if (!tableExists($conn, 'lots') || !tableExists($conn, 'lot_payment_transactions')) {
        return $stats;
      }

      $normalizedStatusExpr = "CASE
        WHEN l.status = 'Installments' THEN 'Installment'
        WHEN l.status = 'Sold' THEN 'Paid'
        WHEN l.status = '' OR l.status IS NULL THEN 'Available'
        ELSE l.status
      END";

      $lotsSql = "
        SELECT
          l.id,
          l.owner_id,
          l.lot_price,
          l.payment_type,
          l.payment_amount,
          l.payment_due_day,
          l.payment_deadline,
          {$normalizedStatusExpr} AS normalized_status,
          IFNULL(tx.total_paid, 0) AS total_paid,
          IFNULL(tx.this_month_paid, 0) AS this_month_paid
        FROM lots l
        LEFT JOIN (
          SELECT
            lot_id,
            IFNULL(SUM(amount), 0) AS total_paid,
            IFNULL(SUM(CASE
              WHEN YEAR(payment_date) = YEAR(CURDATE())
               AND MONTH(payment_date) = MONTH(CURDATE())
              THEN amount ELSE 0 END), 0) AS this_month_paid
          FROM lot_payment_transactions
          GROUP BY lot_id
        ) tx ON tx.lot_id = l.id
        WHERE l.owner_id IS NOT NULL
      ";
      if ($location_id) {
        $lotsSql .= " AND l.location_id = " . (int)$location_id;
      }

      $lotsRes = mysqli_query($conn, $lotsSql);
      if (!$lotsRes) {
        return $stats;
      }

      $today = new DateTime('today');
      $weekEnd = (clone $today)->modify('+7 day');
      $currentMonthStart = new DateTime($today->format('Y-m-01'));
      $monthStartText = $currentMonthStart->format('Y-m-d');
      $monthEndText = (clone $currentMonthStart)->modify('last day of this month')->format('Y-m-d');

      // Cache this month's payments per lot so risk checks compare against amount paid by due date.
      $paymentsByLot = [];
      $paymentsSql = "
        SELECT t.lot_id, DATE(t.payment_date) AS payment_day, IFNULL(t.amount, 0) AS amount
        FROM lot_payment_transactions t
        INNER JOIN lots l ON l.id = t.lot_id
        WHERE DATE(t.payment_date) BETWEEN '{$monthStartText}' AND '{$monthEndText}'
      ";
      if ($location_id) {
        $paymentsSql .= " AND l.location_id = " . (int)$location_id;
      }

      $paymentsRes = mysqli_query($conn, $paymentsSql);
      if ($paymentsRes) {
        while ($paymentRow = mysqli_fetch_assoc($paymentsRes)) {
          $paymentLotId = (int)($paymentRow['lot_id'] ?? 0);
          if ($paymentLotId <= 0) {
            continue;
          }

          if (!isset($paymentsByLot[$paymentLotId])) {
            $paymentsByLot[$paymentLotId] = [];
          }

          $paymentDay = (string)($paymentRow['payment_day'] ?? '');
          if ($paymentDay === '') {
            continue;
          }

          $paymentsByLot[$paymentLotId][] = [
            'day' => $paymentDay,
            'amount' => (float)($paymentRow['amount'] ?? 0),
          ];
        }
      }

      while ($row = mysqli_fetch_assoc($lotsRes)) {
        $lotId = (int)($row['id'] ?? 0);
        $lotPrice = (float)($row['lot_price'] ?? 0);
        $totalPaid = (float)($row['total_paid'] ?? 0);
        $normalizedStatus = trim((string)($row['normalized_status'] ?? ''));
        $paymentType = trim((string)($row['payment_type'] ?? ''));

        $isFullyPaid = (
          $normalizedStatus === 'Paid'
          || $paymentType === 'Fully Paid'
          || ($lotPrice > 0 && $totalPaid >= $lotPrice)
        );
        if ($isFullyPaid) {
          continue;
        }

        $isInstallment = (
          $normalizedStatus === 'Installment'
          || $paymentType === 'Down Payment'
          || ($totalPaid > 0 && $lotPrice > 0 && $totalPaid < $lotPrice)
        );
        if (!$isInstallment) {
          continue;
        }

        $dueDay = (int)($row['payment_due_day'] ?? 0);
        $deadlineRaw = trim((string)($row['payment_deadline'] ?? ''));
        $deadlineTs = ($deadlineRaw !== '' && strpos($deadlineRaw, '0000-00-00') !== 0) ? strtotime($deadlineRaw) : false;
        if ($dueDay < 1 || $dueDay > 31) {
          $dueDay = $deadlineTs ? (int)date('j', $deadlineTs) : 1;
        }

        $dueDate = new DateTime($today->format('Y-m-01'));
        $daysInMonth = (int)$dueDate->format('t');
        $dueDate->setDate((int)$today->format('Y'), (int)$today->format('m'), min(max($dueDay, 1), $daysInMonth));

        if ($deadlineTs) {
          $anchorMonthStart = new DateTime(date('Y-m-01', $deadlineTs));
          if ($currentMonthStart < $anchorMonthStart) {
            continue;
          }
        }

        $remainingBalance = max(0, $lotPrice - $totalPaid);
        $scheduledAmount = max(0, (float)($row['payment_amount'] ?? 0));
        $requiredThisMonth = $scheduledAmount > 0
          ? min($scheduledAmount, $remainingBalance > 0 ? $remainingBalance : $scheduledAmount)
          : $remainingBalance;

        if ($requiredThisMonth <= 0) {
          continue;
        }

        $lotPayments = $paymentsByLot[$lotId] ?? [];
        $paidThisMonthTotal = 0.0;
        $paidUntilToday = 0.0;
        $paidUntilDueDate = 0.0;
        $todayText = $today->format('Y-m-d');
        $dueDateText = $dueDate->format('Y-m-d');

        foreach ($lotPayments as $tx) {
          $txDay = (string)($tx['day'] ?? '');
          $txAmount = (float)($tx['amount'] ?? 0);
          if ($txDay === '' || $txAmount <= 0) {
            continue;
          }

          $paidThisMonthTotal += $txAmount;
          if ($txDay <= $todayText) {
            $paidUntilToday += $txAmount;
          }
          if ($txDay <= $dueDateText) {
            $paidUntilDueDate += $txAmount;
          }
        }

        $stats['expected_this_month'] += $requiredThisMonth;
        $stats['collected_this_month'] += min($paidThisMonthTotal, $requiredThisMonth);

        if ($dueDate < $today) {
          if ($paidUntilDueDate + 0.00001 < $requiredThisMonth) {
            $stats['missed_this_month']++;
            $stats['overdue_installments']++;
          }
        } elseif ($dueDate <= $weekEnd) {
          if ($paidUntilToday + 0.00001 < $requiredThisMonth) {
            $stats['due_this_week']++;
          }
        }
      }

      return $stats;
    }

    $monthly_sales = buildMonthlySalesTrend($conn, null, null, null, $salesDateCol, $salesLocationCol, $salesAmountExprRoot);

  function normalizeAnalyticsDate($dateRaw): ?string {
    $dateRaw = trim((string)$dateRaw);
    if ($dateRaw === '') {
      return null;
    }

    $formats = ['Y-m-d', 'm/d/Y', 'n/j/Y', 'd/m/Y', 'j/n/Y'];
    foreach ($formats as $format) {
      $dateObj = DateTime::createFromFormat($format, $dateRaw);
      if ($dateObj && $dateObj->format($format) === $dateRaw) {
        return $dateObj->format('Y-m-d');
      }
    }

    $ts = strtotime($dateRaw);
    if ($ts !== false) {
      return date('Y-m-d', $ts);
    }

    return null;
  }

  function getAnalyticsPeriodRange(string $salesPeriod): array {
    $salesPeriod = strtolower(trim($salesPeriod));
    switch ($salesPeriod) {
      case 'daily':
        return [date('Y-m-d', strtotime('-30 days')), date('Y-m-d')];
      case 'weekly':
        return [date('Y-m-d', strtotime('-12 weeks')), date('Y-m-d')];
      case 'yearly':
        return [date('Y-m-d', strtotime('-5 years')), date('Y-m-d')];
      case 'monthly':
      default:
        return [date('Y-m-d', strtotime('-12 months')), date('Y-m-d')];
    }
  }

  function getPrintableReportPeriodRange(string $salesPeriod): array {
    $salesPeriod = strtolower(trim($salesPeriod));
    switch ($salesPeriod) {
      case 'daily':
        return [date('Y-m-d'), date('Y-m-d')];
      case 'weekly':
        return [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')];
      case 'yearly':
        return [date('Y-01-01'), date('Y-m-d')];
      case 'monthly':
      default:
        return [date('Y-m-01'), date('Y-m-d')];
    }
  }

  function buildAnalyticsSnapshot($conn, ?string $date_from, ?string $date_to, ?int $location_id, ?string $salesDateCol, ?string $salesLocationCol, ?string $salesAmountExprRoot, string $salesPeriod = 'monthly'): array {
    $salesPeriod = strtolower(trim($salesPeriod));
    if (!in_array($salesPeriod, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
      $salesPeriod = 'monthly';
    }

    if (!$date_from && !$date_to) {
      [$date_from, $date_to] = getAnalyticsPeriodRange($salesPeriod);
    }

    if (tableExists($conn, 'lot_payment_transactions') && tableExists($conn, 'lots')) {
      $salesQuery = "
        SELECT IFNULL(SUM(t.amount), 0) as total
        FROM lot_payment_transactions t
        INNER JOIN lots l ON l.id = t.lot_id
        WHERE 1
      ";
      $salesWhere = [];
      if ($date_from) {
        $salesWhere[] = "t.payment_date >= '" . mysqli_real_escape_string($conn, $date_from) . " 00:00:00'";
      }
      if ($date_to) {
        $salesWhere[] = "t.payment_date < DATE_ADD('" . mysqli_real_escape_string($conn, $date_to) . "', INTERVAL 1 DAY)";
      }
      if ($location_id) {
        $salesWhere[] = "l.location_id = " . (int)$location_id;
      }
      if (!empty($salesWhere)) {
        $salesQuery .= " AND " . implode(' AND ', $salesWhere);
      }
    } else {
      $salesQuery = $salesAmountExprRoot
        ? "SELECT IFNULL(SUM($salesAmountExprRoot), 0) as total FROM sales WHERE 1"
        : "SELECT 0 as total";

      $salesWhere = [];
      if ($salesDateCol && $date_from) {
        $salesWhere[] = "$salesDateCol >= '" . mysqli_real_escape_string($conn, $date_from) . " 00:00:00'";
      }
      if ($salesDateCol && $date_to) {
        $salesWhere[] = "$salesDateCol < DATE_ADD('" . mysqli_real_escape_string($conn, $date_to) . "', INTERVAL 1 DAY)";
      }
      if ($salesLocationCol && $location_id) {
        $salesWhere[] = "$salesLocationCol = " . (int)$location_id;
      }
      if (!empty($salesWhere)) {
        $salesQuery .= " AND " . implode(' AND ', $salesWhere);
      }
    }
    $lotsQuery = "SELECT COUNT(*) as total FROM lots WHERE 1";
    $agentsQuery = "SELECT COUNT(*) as total FROM agent_accounts WHERE status = 'active' AND availability = 1";

    if ($location_id) {
      $lotsQuery .= " AND location_id = " . (int)$location_id;
    }

    $closedSales = 0;
    $ongoingSales = 0;
    if (tableExists($conn, 'lots')) {
      $normalizedStatusExpr = "CASE
        WHEN l.status = 'Installments' THEN 'Installment'
        WHEN l.status = 'Sold' THEN 'Paid'
        WHEN l.status = '' OR l.status IS NULL THEN 'Available'
        ELSE l.status
      END";

      $closedCond = "(
        {$normalizedStatusExpr} = 'Paid'
        OR l.payment_type = 'Fully Paid'
        OR (IFNULL(tx.total_paid, 0) >= IFNULL(l.lot_price, 0) AND IFNULL(l.lot_price, 0) > 0)
      )";
      $ongoingCond = "(
        {$normalizedStatusExpr} = 'Installment'
        OR l.payment_type = 'Down Payment'
        OR (
          IFNULL(tx.total_paid, 0) > 0
          AND IFNULL(l.lot_price, 0) > 0
          AND IFNULL(tx.total_paid, 0) < IFNULL(l.lot_price, 0)
        )
      )";

      $lotSalesWhere = ["l.owner_id IS NOT NULL"];
      if ($location_id) {
        $lotSalesWhere[] = "l.location_id = " . (int)$location_id;
      }
      if (columnExists($conn, 'lots', 'created_at') && $date_from) {
        $lotSalesWhere[] = "l.created_at >= '" . mysqli_real_escape_string($conn, $date_from) . " 00:00:00'";
      }
      if (columnExists($conn, 'lots', 'created_at') && $date_to) {
        $lotSalesWhere[] = "l.created_at < DATE_ADD('" . mysqli_real_escape_string($conn, $date_to) . "', INTERVAL 1 DAY)";
      }

      $lotSalesSql = "
        SELECT
          SUM(CASE WHEN {$closedCond} THEN 1 ELSE 0 END) AS closed_sales,
          SUM(CASE WHEN ({$ongoingCond}) AND NOT ({$closedCond}) THEN 1 ELSE 0 END) AS ongoing_sales
        FROM lots l
        LEFT JOIN (
          SELECT lot_id, IFNULL(SUM(amount), 0) AS total_paid
          FROM lot_payment_transactions
          GROUP BY lot_id
        ) tx ON tx.lot_id = l.id
        WHERE " . implode(' AND ', $lotSalesWhere);

      $lotSalesRes = mysqli_query($conn, $lotSalesSql);
      if ($lotSalesRes) {
        $lotSalesRow = mysqli_fetch_assoc($lotSalesRes);
        $closedSales = (int)($lotSalesRow['closed_sales'] ?? 0);
        $ongoingSales = (int)($lotSalesRow['ongoing_sales'] ?? 0);
      }
    }

    $monthlySales = buildMonthlySalesTrend(
      $conn,
      $date_from,
      $date_to,
      $location_id,
      $salesDateCol,
      $salesLocationCol,
      $salesAmountExprRoot,
      $salesPeriod
    );

    $salesResult = mysqli_query($conn, $salesQuery);
    $lotsResult = mysqli_query($conn, $lotsQuery);
    $agentsResult = mysqli_query($conn, $agentsQuery);

    $locationName = 'All Locations';
    if ($location_id) {
      $locationLookup = mysqli_query($conn, "SELECT location_name FROM lot_locations WHERE id = " . (int)$location_id . " LIMIT 1");
      $locationRow = $locationLookup ? mysqli_fetch_assoc($locationLookup) : null;
      if ($locationRow && !empty($locationRow['location_name'])) {
        $locationName = (string)$locationRow['location_name'];
      }
    }

    return [
      'kpis' => [
        'total_sales' => $salesResult ? (float)(mysqli_fetch_assoc($salesResult)['total'] ?? 0) : 0,
        'closed_sales' => $closedSales,
        'ongoing_sales' => $ongoingSales,
        'total_lots' => $lotsResult ? (int)(mysqli_fetch_assoc($lotsResult)['total'] ?? 0) : 0,
        'available_agents' => $agentsResult ? (int)(mysqli_fetch_assoc($agentsResult)['total'] ?? 0) : 0,
        'pending_documents' => getPendingDocumentsCount($conn),
      ],
      'monthly_sales' => $monthlySales,
      'monthly_scope' => (!$date_from && !$date_to) ? 'default_period' : 'filtered_range',
      'filters' => [
        'date_from' => $date_from,
        'date_to' => $date_to,
        'location_id' => $location_id,
        'location_name' => $locationName,
        'sales_period' => $salesPeriod,
      ],
    ];
  }

  // Handle fetching analytics data
  if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch']) && $_GET['fetch'] === 'analytics') {
      $date_from = normalizeAnalyticsDate($_GET['date_from'] ?? null);
      $date_to = normalizeAnalyticsDate($_GET['date_to'] ?? null);
      $location_id = isset($_GET['location_id']) ? intval($_GET['location_id']) : null;
      $sales_period = strtolower(trim((string)($_GET['sales_period'] ?? 'monthly')));
      if (!in_array($sales_period, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
        $sales_period = 'monthly';
      }

      if (($date_from === null && !empty($_GET['date_from'])) || ($date_to === null && !empty($_GET['date_to']))) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid date format. Please use YYYY-MM-DD.']);
        exit;
      }
      if ($date_from && $date_to && strtotime($date_from) > strtotime($date_to)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Date From cannot be later than Date To.']);
        exit;
      }

      $snapshot = buildAnalyticsSnapshot(
        $conn,
        $date_from,
        $date_to,
        $location_id,
        $salesDateCol,
        $salesLocationCol,
        $salesAmountExprRoot,
        $sales_period
      );

      header('Content-Type: application/json');
      echo json_encode(array_merge(['success' => true], $snapshot));
      exit;
  }

  if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch']) && $_GET['fetch'] === 'reports_data') {
      $date_from = normalizeAnalyticsDate($_GET['date_from'] ?? null);
      $date_to = normalizeAnalyticsDate($_GET['date_to'] ?? null);
      $location_id = isset($_GET['location_id']) ? intval($_GET['location_id']) : null;
      $agent_id = isset($_GET['agent_id']) ? intval($_GET['agent_id']) : null;
      $sales_period = strtolower(trim((string)($_GET['sales_period'] ?? 'monthly')));
      if (!in_array($sales_period, ['daily', 'weekly', 'monthly', 'yearly', 'custom'], true)) {
        $sales_period = 'monthly';
      }
      if (!$date_from && !$date_to && $sales_period !== 'custom') {
        [$date_from, $date_to] = getPrintableReportPeriodRange($sales_period);
      }

      if (($date_from === null && !empty($_GET['date_from'])) || ($date_to === null && !empty($_GET['date_to']))) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid date format. Please use YYYY-MM-DD.']);
        exit;
      }
      if ($date_from && $date_to && strtotime($date_from) > strtotime($date_to)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Date From cannot be later than Date To.']);
        exit;
      }

      $snapshot = buildAnalyticsSnapshot(
        $conn,
        $date_from,
        $date_to,
        $location_id,
        $salesDateCol,
        $salesLocationCol,
        $salesAmountExprRoot,
        $sales_period === 'custom' ? 'monthly' : $sales_period
      );

      $agents = [];
      if (tableExists($conn, 'agent_accounts')) {
        $availabilityExpr = columnExists($conn, 'agent_accounts', 'availability')
          ? 'availability'
          : (columnExists($conn, 'agent_accounts', 'is_available') ? 'is_available' : '1');
        $statusExpr = columnExists($conn, 'agent_accounts', 'status') ? 'status' : "'active'";

        $agentsSql = "
          SELECT
            id,
            TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) AS name,
            COALESCE(email, '') AS email,
            COALESCE(mobile, '') AS mobile,
            $statusExpr AS status,
            $availabilityExpr AS availability
          FROM agent_accounts
          ORDER BY first_name ASC, last_name ASC
        ";
        $agentsRes = mysqli_query($conn, $agentsSql);
        if ($agentsRes) {
          while ($row = mysqli_fetch_assoc($agentsRes)) {
            $agents[] = [
              'id' => (int)($row['id'] ?? 0),
              'name' => trim((string)($row['name'] ?? '')) !== '' ? trim((string)$row['name']) : 'Agent',
              'email' => (string)($row['email'] ?? ''),
              'mobile' => (string)($row['mobile'] ?? ''),
              'status' => (string)($row['status'] ?? 'active'),
              'availability' => (int)($row['availability'] ?? 1),
            ];
          }
        }
      }

      $selectedAgentName = 'All Agents';
      if ($agent_id) {
        foreach ($agents as $agentRow) {
          if ((int)($agentRow['id'] ?? 0) === $agent_id) {
            $selectedAgentName = (string)($agentRow['name'] ?? 'Agent');
            break;
          }
        }
        if ($selectedAgentName === 'All Agents') {
          $selectedAgentName = 'Agent #' . $agent_id;
        }
      }

      $closedSales = [];
      $fullyPaidLots = [];
      $agentSoldLots = [];

      if (tableExists($conn, 'lots') && tableExists($conn, 'lot_locations')) {
        $hasLotAgent = columnExists($conn, 'lots', 'agent_id');
        $hasUserAgent = tableExists($conn, 'user_accounts') && columnExists($conn, 'user_accounts', 'agent_id');
        $hasViewings = tableExists($conn, 'viewings')
          && columnExists($conn, 'viewings', 'lot_id')
          && columnExists($conn, 'viewings', 'agent_id');

        $lotAgentExpr = $hasLotAgent ? 'IFNULL(l.agent_id, 0)' : '0';
        $userAgentExpr = $hasUserAgent ? 'IFNULL(u.agent_id, 0)' : '0';

        $viewingJoin = $hasViewings
          ? "LEFT JOIN (\n              SELECT v1.lot_id, v1.agent_id\n              FROM viewings v1\n              INNER JOIN (\n                SELECT lot_id, MAX(id) AS latest_id\n                FROM viewings\n                WHERE lot_id IS NOT NULL\n                GROUP BY lot_id\n              ) lv ON lv.latest_id = v1.id\n            ) rv ON rv.lot_id = l.id"
          : "LEFT JOIN (SELECT NULL AS lot_id, 0 AS agent_id) rv ON rv.lot_id = l.id";

        $normalizedStatusExpr = "CASE
          WHEN l.status = 'Installments' THEN 'Installment'
          WHEN l.status = 'Sold' THEN 'Paid'
          WHEN l.status = '' OR l.status IS NULL THEN 'Available'
          ELSE l.status
        END";

        $reportSql = "
          SELECT
            l.id,
            l.block_number,
            l.lot_number,
            l.lot_price,
            l.payment_type,
            l.payment_amount,
            l.payment_deadline,
            ll.location_name,
            COALESCE(tx.total_paid, 0) AS total_paid,
            tx.last_payment_date,
            $normalizedStatusExpr AS normalized_status,
            IFNULL(NULLIF($lotAgentExpr, 0), IFNULL(NULLIF($userAgentExpr, 0), IFNULL(rv.agent_id, 0))) AS resolved_agent_id,
            COALESCE(TRIM(CONCAT(COALESCE(a.first_name, ''), ' ', COALESCE(a.last_name, ''))), '') AS resolved_agent_name,
            COALESCE(a.email, '') AS resolved_agent_email,
            COALESCE(TRIM(CONCAT(COALESCE(ua.first_name, ''), ' ', COALESCE(ua.last_name, ''))), '') AS owner_name
          FROM lots l
          LEFT JOIN lot_locations ll ON ll.id = l.location_id
          LEFT JOIN user_accounts u ON u.id = l.owner_id
          LEFT JOIN user_accounts ua ON ua.id = l.owner_id
          LEFT JOIN (
            SELECT lot_id, IFNULL(SUM(amount), 0) AS total_paid, MAX(payment_date) AS last_payment_date
            FROM lot_payment_transactions
            GROUP BY lot_id
          ) tx ON tx.lot_id = l.id
          $viewingJoin
          LEFT JOIN agent_accounts a ON a.id = IFNULL(NULLIF($lotAgentExpr, 0), IFNULL(NULLIF($userAgentExpr, 0), IFNULL(rv.agent_id, 0)))
          WHERE l.owner_id IS NOT NULL
        ";

        if ($location_id) {
          $reportSql .= " AND l.location_id = " . (int)$location_id;
        }

        $reportSql .= " ORDER BY COALESCE(tx.last_payment_date, l.payment_deadline, CURDATE()) DESC, l.id DESC";

        $reportRes = mysqli_query($conn, $reportSql);
        if ($reportRes) {
          while ($row = mysqli_fetch_assoc($reportRes)) {
            $lotPrice = (float)($row['lot_price'] ?? 0);
            $totalPaid = (float)($row['total_paid'] ?? 0);
            $normalizedStatus = trim((string)($row['normalized_status'] ?? ''));
            $paymentType = trim((string)($row['payment_type'] ?? ''));
            $resolvedAgentId = (int)($row['resolved_agent_id'] ?? 0);

            $isFullyPaid = (
              $normalizedStatus === 'Paid'
              || $paymentType === 'Fully Paid'
              || ($lotPrice > 0 && $totalPaid >= $lotPrice)
            );
            if (!$isFullyPaid) {
              continue;
            }

            $recordDate = trim((string)($row['last_payment_date'] ?? ''));
            if ($recordDate === '') {
              $recordDate = trim((string)($row['payment_deadline'] ?? ''));
            }
            if ($recordDate !== '') {
              $recordDate = substr($recordDate, 0, 10);
            }

            if ($date_from && $recordDate !== '' && $recordDate < $date_from) {
              continue;
            }
            if ($date_to && $recordDate !== '' && $recordDate > $date_to) {
              continue;
            }
            if ($agent_id && $resolvedAgentId !== $agent_id) {
              continue;
            }

            $rowData = [
              'lot_id' => (int)($row['id'] ?? 0),
              'property' => 'Block ' . ($row['block_number'] ?? 'N/A') . ', Lot ' . ($row['lot_number'] ?? 'N/A'),
              'location_name' => (string)($row['location_name'] ?? 'N/A'),
              'owner_name' => trim((string)($row['owner_name'] ?? '')) !== '' ? trim((string)$row['owner_name']) : 'Client',
              'agent_id' => $resolvedAgentId,
              'agent_name' => trim((string)($row['resolved_agent_name'] ?? '')) !== '' ? trim((string)$row['resolved_agent_name']) : 'Unassigned',
              'agent_email' => (string)($row['resolved_agent_email'] ?? ''),
              'status' => 'Fully Paid',
              'payment_type' => $paymentType !== '' ? $paymentType : 'Fully Paid',
              'total_paid' => $totalPaid,
              'lot_price' => $lotPrice,
              'closed_amount' => $totalPaid > 0 ? $totalPaid : $lotPrice,
              'closed_date' => $recordDate,
            ];

            $closedSales[] = $rowData;
            $fullyPaidLots[] = $rowData;
            $agentSoldLots[] = $rowData;
          }
        }
      }

      if (!$agent_id) {
        $agentSoldLots = $closedSales;
      }

      $reportTotalSalesAmount = 0.0;
      foreach ($closedSales as $saleRow) {
        $reportTotalSalesAmount += (float)($saleRow['closed_amount'] ?? 0);
      }

      $reportKpis = $snapshot['kpis'] ?? [];
      $reportKpis['total_sales'] = $reportTotalSalesAmount;
      $reportKpis['closed_sales'] = count($closedSales);

      header('Content-Type: application/json');
      echo json_encode([
        'success' => true,
        'generated_at' => date('Y-m-d H:i:s'),
        'filters' => [
          'date_from' => $date_from,
          'date_to' => $date_to,
          'location_id' => $location_id,
          'agent_id' => $agent_id,
          'agent_name' => $selectedAgentName,
          'sales_period' => $sales_period,
        ],
        'kpis' => $reportKpis,
        'agents' => $agents,
        'closed_sales' => $closedSales,
        'fully_paid_lots' => $fullyPaidLots,
        'agent_sold_lots' => $agentSoldLots,
      ]);
      exit;
  }


  if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['export']) && $_GET['export'] === 'analytics') {
      $date_from = normalizeAnalyticsDate($_GET['date_from'] ?? null);
      $date_to = normalizeAnalyticsDate($_GET['date_to'] ?? null);
      $location_id = isset($_GET['location_id']) ? intval($_GET['location_id']) : null;
      $sales_period = strtolower(trim((string)($_GET['sales_period'] ?? 'monthly')));
      if (!in_array($sales_period, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
        $sales_period = 'monthly';
      }

      if (($date_from === null && !empty($_GET['date_from'])) || ($date_to === null && !empty($_GET['date_to']))) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid date format. Please use YYYY-MM-DD.']);
        exit;
      }
      if ($date_from && $date_to && strtotime($date_from) > strtotime($date_to)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Date From cannot be later than Date To.']);
        exit;
      }

      $snapshot = buildAnalyticsSnapshot(
        $conn,
        $date_from,
        $date_to,
        $location_id,
        $salesDateCol,
        $salesLocationCol,
        $salesAmountExprRoot,
        $sales_period
      );

      $topAgentsRows = buildTopAgentsLeaderboard(
        $conn,
        $date_from,
        $date_to,
        $location_id,
        $salesDateCol,
        $salesLocationCol,
        $salesAmountExprWithAlias
      );

      $filenameDate = date('Ymd_His');
      header('Content-Type: text/html; charset=utf-8');
      header('Content-Disposition: attachment; filename="analytics_report_' . $filenameDate . '.html"');
      header('Cache-Control: max-age=0');

      $generatedAt = date('Y-m-d H:i:s');
      $dateFromText = $snapshot['filters']['date_from'] ?: 'N/A';
      $dateToText = $snapshot['filters']['date_to'] ?: 'N/A';
      $locationText = $snapshot['filters']['location_name'] ?: 'All Locations';

      // Build HTML report
      $html = '<html>';
      $html .= '<head>';
      $html .= '<meta charset="utf-8">';
      $html .= '<title>Nuevo Puerta Analytics Report</title>';
      $html .= '<style>';
      $html .= 'body { font-family: Arial, sans-serif; padding: 16px; color: #111; }';
      $html .= 'h3, h4 { color: #1f3d1f; margin: 0 0 8px; }';
      $html .= 'table { width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 16px; }';
      $html .= 'th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }';
      $html .= 'th { background: #f3f4f6; font-weight: bold; }';
      $html .= '.meta { background: #f8f9fa; padding: 12px; border-radius: 4px; margin-bottom: 16px; }';
      $html .= '.meta div { display: flex; gap: 40px; margin: 4px 0; }';
      $html .= '.meta-label { font-weight: bold; min-width: 120px; }';
      $html .= '</style>';
      $html .= '</head>';
      $html .= '<body>';

      $html .= '<h2>Nuevo Puerta - Analytics Report</h2>';
      $html .= '<div class="meta">';
      $html .= '<div><span class="meta-label">Generated At:</span> <span>' . htmlspecialchars($generatedAt) . '</span></div>';
      $html .= '<div><span class="meta-label">Date From:</span> <span>' . htmlspecialchars($dateFromText) . '</span></div>';
      $html .= '<div><span class="meta-label">Date To:</span> <span>' . htmlspecialchars($dateToText) . '</span></div>';
      $html .= '<div><span class="meta-label">Location:</span> <span>' . htmlspecialchars($locationText) . '</span></div>';
      $html .= '<div><span class="meta-label">Period:</span> <span>' . htmlspecialchars(ucfirst($sales_period)) . '</span></div>';
      $html .= '</div>';

      // KPI Summary
      $html .= '<h3>KPI Summary</h3>';
      $html .= '<table>';
      $html .= '<tr><th>Metric</th><th>Value</th></tr>';
      $html .= '<tr><td>Total Sales</td><td>PHP ' . number_format((float)$snapshot['kpis']['total_sales'], 2) . '</td></tr>';
      $html .= '<tr><td>Closed Sales (Fully Paid)</td><td>' . (int)($snapshot['kpis']['closed_sales'] ?? 0) . '</td></tr>';
      $html .= '<tr><td>Ongoing Sales (Installment)</td><td>' . (int)($snapshot['kpis']['ongoing_sales'] ?? 0) . '</td></tr>';
      $html .= '<tr><td>Total Lots</td><td>' . (int)$snapshot['kpis']['total_lots'] . '</td></tr>';
      $html .= '<tr><td>Available Agents</td><td>' . (int)$snapshot['kpis']['available_agents'] . '</td></tr>';
      $html .= '<tr><td>Pending Documents</td><td>' . (int)$snapshot['kpis']['pending_documents'] . '</td></tr>';
      $html .= '</table>';

      // Sales Trend
      $html .= '<h3>Sales Trend (' . htmlspecialchars(ucfirst($sales_period)) . ')</h3>';
      $html .= '<table>';
      $html .= '<tr><th>Period</th><th>Sales Amount</th></tr>';
      if (empty($snapshot['monthly_sales'])) {
        $html .= '<tr><td colspan="2">No sales records for selected filters.</td></tr>';
      } else {
        foreach ($snapshot['monthly_sales'] as $row) {
          $period = htmlspecialchars((string)($row['period'] ?? $row['month'] ?? ''));
          $amount = number_format((float)$row['amount'], 2);
          $html .= '<tr><td>' . $period . '</td><td>PHP ' . $amount . '</td></tr>';
        }
      }
      $html .= '</table>';

      // Top Agents
      $html .= '<h3>Top Agents</h3>';
      $html .= '<table>';
      $html .= '<tr><th>Agent Name</th><th>Email</th><th>Sales Count</th><th>Sold Lots</th><th>Reserved Lots</th><th>Ongoing Lots</th><th>Cancelled Lots</th><th>Total Sales</th><th>Average Deal Size</th></tr>';
      if (empty($topAgentsRows)) {
        $html .= '<tr><td colspan="9">No top-agent sales records for selected filters.</td></tr>';
      } else {
        foreach ($topAgentsRows as $row) {
          $html .= '<tr>';
          $html .= '<td>' . htmlspecialchars((string)($row['name'] ?? '')) . '</td>';
          $html .= '<td>' . htmlspecialchars((string)($row['email'] ?? '')) . '</td>';
          $html .= '<td>' . (int)($row['sales_count'] ?? 0) . '</td>';
          $html .= '<td>' . (int)($row['sold_lots_count'] ?? 0) . '</td>';
          $html .= '<td>' . (int)($row['reserved_lots_count'] ?? 0) . '</td>';
          $html .= '<td>' . (int)($row['ongoing_lots_count'] ?? 0) . '</td>';
          $html .= '<td>' . (int)($row['cancelled_lots_count'] ?? 0) . '</td>';
          $html .= '<td>PHP ' . number_format((float)($row['total_amount'] ?? 0), 2) . '</td>';
          $html .= '<td>PHP ' . number_format((float)($row['avg_deal_size'] ?? 0), 2) . '</td>';
          $html .= '</tr>';
        }
      }
      $html .= '</table>';

      $html .= '</body>';
      $html .= '</html>';

      echo $html;
      exit;
  }


  /* ============================================================
    CORE HELPERS
  ============================================================ */

  // Respond with JSON (clears any buffered PHP warnings before sending)
  function respondJSON($data) {
      if (ob_get_level() > 0) ob_clean();
      header("Content-Type: application/json");
      echo json_encode($data);
      exit;
  }

  // Audit Log Writer
  function logAudit($conn, $admin_id, $action, $details) {
      $stmt = $conn->prepare("
          INSERT INTO audit_logs (admin_id, action, details) 
          VALUES (?, ?, ?)
      ");
      $stmt->bind_param("iss", $admin_id, $action, $details);
      $stmt->execute();
      $stmt->close();
  }

  // Send Notification
  function sendNotification($conn, $title, $message, $type = 'info') {
      $stmt = $conn->prepare("
          INSERT INTO notifications (title, message, type) 
          VALUES (?, ?, ?)
      ");
      $stmt->bind_param("sss", $title, $message, $type);
      $stmt->execute();
      $stmt->close();
  }

  // File Upload Helper
  function safeUploadFile($fileKey, $folder = "uploads/documents/") {
      if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== 0) return null;

      if (!is_dir($folder)) mkdir($folder, 0777, true);

      $ext = strtolower(pathinfo($_FILES[$fileKey]['name'], PATHINFO_EXTENSION));
      $newName = time() . "_" . rand(1000, 9999) . "." . $ext;
      $path = $folder . $newName;

      if (move_uploaded_file($_FILES[$fileKey]["tmp_name"], $path)) {
          return $path;
      }
      return null;
  }

      function getDocumentPendingFilterSql($alias = 'd') {
        return "$alias.status IN ('pending', 'pending_review', 'under_review')";
      }

      function getPendingDocumentsCount($conn): int {
        $total = 0;

        if (tableExists($conn, 'user_documents') && columnExists($conn, 'user_documents', 'status')) {
          $query = "SELECT COUNT(*) AS total FROM user_documents d WHERE " . getDocumentPendingFilterSql('d');
          $result = mysqli_query($conn, $query);
          if ($result && ($row = mysqli_fetch_assoc($result))) {
            $total += (int)($row['total'] ?? 0);
          }
        }

        if (tableExists($conn, 'documents') && columnExists($conn, 'documents', 'status')) {
          $query = "SELECT COUNT(*) AS total FROM documents d WHERE " . getDocumentPendingFilterSql('d');
          $result = mysqli_query($conn, $query);
          if ($result && ($row = mysqli_fetch_assoc($result))) {
            $total += (int)($row['total'] ?? 0);
          }
        }

        return $total;
      }

        function updateDocumentReviewStatus($conn, $table, $doc_id, $status, $remarks = '', $admin_id = null) {
          if (!tableExists($conn, $table) || !columnExists($conn, $table, 'id') || !columnExists($conn, $table, 'status')) {
            return [false, "Table or required columns missing: $table"];
          }

          $statusEsc = mysqli_real_escape_string($conn, $status);
          $remarksEsc = mysqli_real_escape_string($conn, $remarks);

          $setParts = ["status='$statusEsc'"];
          if (columnExists($conn, $table, 'reviewed_at')) {
            $setParts[] = "reviewed_at=NOW()";
          }
          if ($admin_id && columnExists($conn, $table, 'reviewed_by')) {
            $setParts[] = "reviewed_by=" . intval($admin_id);
          }

          if ($status === 'rejected') {
            if (columnExists($conn, $table, 'progress_notes')) {
              $setParts[] = "progress_notes='$remarksEsc'";
            }
            if (columnExists($conn, $table, 'remarks')) {
              $setParts[] = "remarks='$remarksEsc'";
            }
          }

          $q = "UPDATE $table SET " . implode(', ', $setParts) . " WHERE id = " . intval($doc_id);
          $ok = mysqli_query($conn, $q);
          if (!$ok) {
            return [false, mysqli_error($conn) ?: "Failed to update $table"];
          }

          if (mysqli_affected_rows($conn) > 0) {
            return [true, null];
          }

          // Treat as success if the document exists but update made no changes.
          $check = mysqli_query($conn, "SELECT id FROM $table WHERE id = " . intval($doc_id) . " LIMIT 1");
          if ($check && mysqli_fetch_assoc($check)) {
            return [true, null];
          }

          return [false, "Document not found in $table"];
        }

  /* ============================================================
    FETCH HANDLERS — GET Requests
  ============================================================ */

  // ------------------------------------------------------------
  // Fetch Audit Logs
  // ------------------------------------------------------------
  if (isset($_GET['fetch']) && $_GET['fetch'] === 'audit_logs') {

      if (!tableExists($conn, 'audit_logs')) {
        respondJSON([]);
      }

        $actionFilter = trim($_GET['action'] ?? '');
        $adminFilter = trim($_GET['admin'] ?? '');
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo = trim($_GET['date_to'] ?? '');
        $search = trim($_GET['search'] ?? '');

        $where = [];
        if ($actionFilter !== '') {
          $where[] = "a.action LIKE '%" . mysqli_real_escape_string($conn, $actionFilter) . "%'";
        }
        if ($adminFilter !== '') {
          $adminEsc = mysqli_real_escape_string($conn, $adminFilter);
          $where[] = "(ad.first_name LIKE '%$adminEsc%' OR ad.last_name LIKE '%$adminEsc%')";
        }
        if ($dateFrom !== '') {
          $where[] = "a.created_at >= '" . mysqli_real_escape_string($conn, $dateFrom) . " 00:00:00'";
        }
        if ($dateTo !== '') {
          $where[] = "a.created_at < DATE_ADD('" . mysqli_real_escape_string($conn, $dateTo) . "', INTERVAL 1 DAY)";
        }
        if ($search !== '') {
          $searchEsc = mysqli_real_escape_string($conn, $search);
          $where[] = "(a.action LIKE '%$searchEsc%' OR a.details LIKE '%$searchEsc%')";
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $q = "
          SELECT a.*, ad.first_name, ad.last_name
          FROM audit_logs a
          LEFT JOIN admin_accounts ad ON ad.id = a.admin_id
          $whereSql
          ORDER BY a.created_at DESC
          LIMIT 200
        ";
      $res = mysqli_query($conn, $q);

      $logs = [];
      while ($row = mysqli_fetch_assoc($res)) {
          $logs[] = $row;
      }

      respondJSON($logs);
  }


  // ------------------------------------------------------------
  // Fetch Notifications
  // ------------------------------------------------------------
  if (isset($_GET['fetch']) && $_GET['fetch'] === 'notifications') {

      if (!tableExists($conn, 'notifications')) {
        respondJSON([]);
      }

        $typeFilter = trim($_GET['type'] ?? '');
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo = trim($_GET['date_to'] ?? '');
        $search = trim($_GET['search'] ?? '');

        $where = [];
        $allowedTypes = ['info', 'success', 'warning', 'error'];
        if ($typeFilter !== '' && in_array($typeFilter, $allowedTypes, true)) {
          $where[] = "type = '" . mysqli_real_escape_string($conn, $typeFilter) . "'";
        }
        if ($dateFrom !== '') {
          $where[] = "created_at >= '" . mysqli_real_escape_string($conn, $dateFrom) . " 00:00:00'";
        }
        if ($dateTo !== '') {
          $where[] = "created_at < DATE_ADD('" . mysqli_real_escape_string($conn, $dateTo) . "', INTERVAL 1 DAY)";
        }
        if ($search !== '') {
          $searchEsc = mysqli_real_escape_string($conn, $search);
          $where[] = "(title LIKE '%$searchEsc%' OR message LIKE '%$searchEsc%')";
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $q = "SELECT * FROM notifications $whereSql ORDER BY created_at DESC LIMIT 100";
      $res = mysqli_query($conn, $q);

      $data = [];
      while ($row = mysqli_fetch_assoc($res)) {
          $data[] = $row;
      }

      respondJSON($data);
  }


  // ------------------------------------------------------------
  // Fetch Notification Counter
  // ------------------------------------------------------------
  if (isset($_GET['fetch']) && $_GET['fetch'] === 'notifications_count') {

      if (!tableExists($conn, 'notifications')) {
        respondJSON(["count" => 0]);
      }

      $res = mysqli_query($conn, "SELECT COUNT(*) AS total FROM notifications");
      $row = mysqli_fetch_assoc($res);

      respondJSON(["count" => intval($row["total"])]);
  }


  // ------------------------------------------------------------
  // Fetch Pending Documents
  // ------------------------------------------------------------
  if (isset($_GET['fetch']) && $_GET['fetch'] === 'documents') {

      $statusFilter = trim($_GET['status'] ?? '');
      $typeFilter = trim($_GET['doc_type'] ?? '');
      $dateFrom = trim($_GET['date_from'] ?? '');
      $dateTo = trim($_GET['date_to'] ?? '');
      $search = trim($_GET['search'] ?? '');

      $userDocAllowedStatuses = ['pending_review', 'under_review', 'approved', 'rejected', 'requires_revision'];
      $legacyDocAllowedStatuses = ['pending', 'approved', 'rejected'];

      if (tableExists($conn, 'user_documents') && columnExists($conn, 'user_documents', 'status')) {
        $where = [];
        if ($statusFilter !== '' && $statusFilter !== 'all' && in_array($statusFilter, $userDocAllowedStatuses, true)) {
          $where[] = "d.status = '" . mysqli_real_escape_string($conn, $statusFilter) . "'";
        } else {
          $where[] = getDocumentPendingFilterSql('d');
        }
        if ($typeFilter !== '' && columnExists($conn, 'user_documents', 'doc_type')) {
          $where[] = "d.doc_type = '" . mysqli_real_escape_string($conn, $typeFilter) . "'";
        }
        if ($dateFrom !== '') {
          $where[] = "d.uploaded_at >= '" . mysqli_real_escape_string($conn, $dateFrom) . " 00:00:00'";
        }
        if ($dateTo !== '') {
          $where[] = "d.uploaded_at < DATE_ADD('" . mysqli_real_escape_string($conn, $dateTo) . "', INTERVAL 1 DAY)";
        }
        if ($search !== '') {
          $searchEsc = mysqli_real_escape_string($conn, $search);
          $where[] = "(d.file_name LIKE '%$searchEsc%' OR d.doc_type LIKE '%$searchEsc%' OR u.first_name LIKE '%$searchEsc%' OR u.last_name LIKE '%$searchEsc%' OR u.email LIKE '%$searchEsc%')";
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $q = "
          SELECT 
            d.id,
            d.doc_type,
            d.file_name,
            d.file_path,
            d.status,
            d.uploaded_at,
            u.first_name,
            u.last_name,
            u.email,
            'user_documents' AS source
          FROM user_documents d
          LEFT JOIN user_accounts u ON d.user_id = u.id
          $whereSql
          ORDER BY d.uploaded_at DESC
        ";
        $res = mysqli_query($conn, $q);

        $docs = [];
        while ($row = mysqli_fetch_assoc($res)) {
          $docs[] = $row;
        }

        respondJSON($docs);
      }

      if (tableExists($conn, 'documents') && columnExists($conn, 'documents', 'status')) {
        $where = [];
        if ($statusFilter !== '' && $statusFilter !== 'all' && in_array($statusFilter, $legacyDocAllowedStatuses, true)) {
          $where[] = "status = '" . mysqli_real_escape_string($conn, $statusFilter) . "'";
        } else {
          $where[] = "status = 'pending'";
        }
        if ($typeFilter !== '') {
          if (columnExists($conn, 'documents', 'doc_type')) {
            $where[] = "doc_type = '" . mysqli_real_escape_string($conn, $typeFilter) . "'";
          } elseif (columnExists($conn, 'documents', 'type')) {
            $where[] = "type = '" . mysqli_real_escape_string($conn, $typeFilter) . "'";
          }
        }
        if ($dateFrom !== '') {
          $where[] = "uploaded_at >= '" . mysqli_real_escape_string($conn, $dateFrom) . " 00:00:00'";
        }
        if ($dateTo !== '') {
          $where[] = "uploaded_at < DATE_ADD('" . mysqli_real_escape_string($conn, $dateTo) . "', INTERVAL 1 DAY)";
        }
        if ($search !== '') {
          $searchEsc = mysqli_real_escape_string($conn, $search);
          $searchParts = [];
          if (columnExists($conn, 'documents', 'filename')) $searchParts[] = "filename LIKE '%$searchEsc%'";
          if (columnExists($conn, 'documents', 'file_name')) $searchParts[] = "file_name LIKE '%$searchEsc%'";
          if (columnExists($conn, 'documents', 'type')) $searchParts[] = "type LIKE '%$searchEsc%'";
          if (columnExists($conn, 'documents', 'doc_type')) $searchParts[] = "doc_type LIKE '%$searchEsc%'";
          if ($searchParts) {
            $where[] = '(' . implode(' OR ', $searchParts) . ')';
          }
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $q = "SELECT *, 'documents' AS source FROM documents $whereSql ORDER BY uploaded_at DESC";
        $res = mysqli_query($conn, $q);

        $docs = [];
        while ($row = mysqli_fetch_assoc($res)) {
          $docs[] = $row;
        }

        respondJSON($docs);
      }

      respondJSON([]);
    }


    // ------------------------------------------------------------
    // Fetch Pending Documents Counter
    // ------------------------------------------------------------
    if (isset($_GET['fetch']) && $_GET['fetch'] === 'documents_count') {
      respondJSON(["count" => getPendingDocumentsCount($conn)]);
    }


  /* ============================================================
    POST HANDLERS — ACTIONS
  ============================================================ */

  // ------------------------------------------------------------
  // Approve Document
  // ------------------------------------------------------------
  if (isset($_POST["action"]) && $_POST["action"] === "approve_document") {

      $doc_id = intval($_POST["doc_id"]);
      $doc_source = $_POST['doc_source'] ?? 'user_documents';
      $updated = false;
      $lastError = null;

      if ($doc_source === 'user_documents') {
        [$updated, $lastError] = updateDocumentReviewStatus($conn, 'user_documents', $doc_id, 'approved', '', $admin_id);
      }

      if (!$updated) {
        [$updated, $lastError] = updateDocumentReviewStatus($conn, 'documents', $doc_id, 'approved', '', $admin_id);
      }

      if (!$updated) {
        respondJSON(["success" => false, "error" => $lastError ?: "Document not found or already updated."]);
      }

      logAudit($conn, $admin_id, "Document Approved", "Document ID: $doc_id approved");
      sendNotification($conn, "Document Approved", "Document #$doc_id was approved.", "success");

      respondJSON(["success" => true]);
  }


  // ------------------------------------------------------------
  // Reject Document
  // ------------------------------------------------------------
  if (isset($_POST["action"]) && $_POST["action"] === "reject_document") {

      $doc_id = intval($_POST["doc_id"]);
      $remarks = $_POST["remarks"] ?? '';
      $doc_source = $_POST['doc_source'] ?? 'user_documents';
      $updated = false;
      $lastError = null;

      if ($doc_source === 'user_documents') {
        [$updated, $lastError] = updateDocumentReviewStatus($conn, 'user_documents', $doc_id, 'rejected', $remarks, $admin_id);
      }

      if (!$updated) {
        [$updated, $lastError] = updateDocumentReviewStatus($conn, 'documents', $doc_id, 'rejected', $remarks, $admin_id);
      }

      if (!$updated) {
        respondJSON(["success" => false, "error" => $lastError ?: "Document not found or already updated."]);
      }

      logAudit($conn, $admin_id, "Document Rejected", "Document ID: $doc_id rejected");
      sendNotification($conn, "Document Rejected", "A document was rejected.", "warning");

      respondJSON(["success" => true]);
  }


  // ------------------------------------------------------------
  // Handle document upload from agents/users (if used)
  // ------------------------------------------------------------
  if (isset($_POST["action"]) && $_POST["action"] === "upload_document") {

      $user_id  = intval($_POST["user_id"] ?? 0);
      $agent_id = intval($_POST["agent_id"] ?? 0);
      $type     = mysqli_real_escape_string($conn, $_POST["type"]);

      $file = safeUploadFile("document_file");
      if (!$file) respondJSON(["success" => false, "error" => "Upload failed"]);

      $stmt = $conn->prepare("
          INSERT INTO documents (user_id, agent_id, filename, type, status) 
          VALUES (?, ?, ?, ?, 'pending')
      ");
      $stmt->bind_param("iiss", $user_id, $agent_id, $file, $type);
      $stmt->execute();
      $stmt->close();

      sendNotification($conn, "New Document Uploaded", "A new $type document was uploaded.", "info");

      respondJSON(["success" => true]);
  }

  // ------------------------------------------------------------
  // Fetch user list for admin upload dropdown
  // ------------------------------------------------------------
  if (isset($_GET['fetch']) && $_GET['fetch'] === 'user_list') {
      $users = [];
      $res = mysqli_query($conn, "SELECT id, first_name, last_name, email FROM user_accounts ORDER BY first_name ASC, last_name ASC");
      if ($res) {
          while ($row = mysqli_fetch_assoc($res)) {
              $users[] = ['id' => (int)$row['id'], 'name' => trim($row['first_name'] . ' ' . $row['last_name']), 'email' => $row['email']];
          }
      }
      respondJSON($users);
  }

  // ------------------------------------------------------------
  // Admin uploads a document soft copy for a user
  // ------------------------------------------------------------
  if (isset($_POST['action']) && $_POST['action'] === 'admin_upload_document') {
    try {
      $user_id  = (int)($_POST['user_id'] ?? 0);
      $lot_id   = isset($_POST['lot_id']) && $_POST['lot_id'] !== '' ? (int)$_POST['lot_id'] : null;
      $doc_type = trim($_POST['doc_type'] ?? '');
      $allowed_types = ['Copy of Contract', 'Copy of Agreement'];

      if ($user_id <= 0) {
          respondJSON(['success' => false, 'error' => 'Please select a client / user.']);
      }
      if (!in_array($doc_type, $allowed_types, true)) {
          respondJSON(['success' => false, 'error' => 'Please select a valid document type.']);
      }

      $uploadError = $_FILES['admin_doc_file']['error'] ?? UPLOAD_ERR_NO_FILE;
      if (!isset($_FILES['admin_doc_file']) || $uploadError !== UPLOAD_ERR_OK) {
          $errMsg = match((int)$uploadError) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is too large. Max size allowed by server.',
            UPLOAD_ERR_NO_FILE => 'No file was selected.',
            default => 'File upload error (code ' . $uploadError . ').',
          };
          respondJSON(['success' => false, 'error' => $errMsg]);
      }

      $ext = strtolower(pathinfo((string)($_FILES['admin_doc_file']['name'] ?? ''), PATHINFO_EXTENSION));
      $allowed_exts = ['pdf', 'jpg', 'jpeg', 'png'];
      if (!in_array($ext, $allowed_exts, true)) {
          respondJSON(['success' => false, 'error' => 'Only PDF, JPG, and PNG files are allowed.']);
      }

      $folder = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'documents' . DIRECTORY_SEPARATOR;
      if (!is_dir($folder)) {
          if (!@mkdir($folder, 0777, true)) {
              respondJSON(['success' => false, 'error' => 'Could not create upload directory. Check server permissions.']);
          }
      }

      $newFileName = time() . '_' . rand(1000, 9999) . '.' . $ext;
      $destPath = $folder . $newFileName;
      $relPath  = 'uploads/documents/' . $newFileName;

      if (!move_uploaded_file($_FILES['admin_doc_file']['tmp_name'], $destPath)) {
          respondJSON(['success' => false, 'error' => 'Failed to save the uploaded file. Check server permissions on uploads/documents/.']);
      }

      $origName    = basename((string)($_FILES['admin_doc_file']['name'] ?? 'document.' . $ext));
      $origNameEsc = mysqli_real_escape_string($conn, $origName);
      $relPathEsc  = mysqli_real_escape_string($conn, $relPath);
      $docTypeEsc  = mysqli_real_escape_string($conn, $doc_type);

      $conn->query("CREATE TABLE IF NOT EXISTS user_documents (
          id INT AUTO_INCREMENT PRIMARY KEY,
          user_id INT NOT NULL,
          doc_type VARCHAR(100) NOT NULL,
          file_name VARCHAR(255) NOT NULL,
          file_path VARCHAR(500) NOT NULL,
          status ENUM('pending_review','under_review','approved','rejected','requires_revision') DEFAULT 'pending_review',
          progress_notes TEXT,
          uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          reviewed_at TIMESTAMP NULL,
          reviewed_by INT NULL,
          INDEX idx_user_docs (user_id, status, uploaded_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      // Ensure columns added after initial table creation exist on existing installs
      $conn->query("ALTER TABLE user_documents ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMP NULL");
      $conn->query("ALTER TABLE user_documents ADD COLUMN IF NOT EXISTS reviewed_by INT NULL");
      $conn->query("ALTER TABLE user_documents ADD COLUMN IF NOT EXISTS lot_id INT NULL");

      // Build INSERT only with columns that actually exist in the table
      $hasReviewedAt = columnExists($conn, 'user_documents', 'reviewed_at');
      $hasReviewedBy = columnExists($conn, 'user_documents', 'reviewed_by');
      $hasLotId = columnExists($conn, 'user_documents', 'lot_id');

      $insertCols = "user_id, doc_type, file_name, file_path, status, uploaded_at";
      $insertVals = "$user_id, '$docTypeEsc', '$origNameEsc', '$relPathEsc', 'approved', NOW()";
      if ($hasReviewedAt) { $insertCols .= ", reviewed_at"; $insertVals .= ", NOW()"; }
      if ($hasReviewedBy) { $insertCols .= ", reviewed_by"; $insertVals .= ", " . intval($admin_id ?? 0); }
      if ($hasLotId) { $insertCols .= ", lot_id"; $insertVals .= ", " . ($lot_id !== null ? $lot_id : "NULL"); }

      $insertSql = "INSERT INTO user_documents ($insertCols) VALUES ($insertVals)";
      if (!mysqli_query($conn, $insertSql)) {
          respondJSON(['success' => false, 'error' => 'Database insert failed: ' . mysqli_error($conn)]);
      }

      $emailSent = false;
      $emailError = '';

      $recipientEmail = '';
      $recipientName = '';
      $userInfoRes = mysqli_query(
        $conn,
        "SELECT first_name, last_name, email FROM user_accounts WHERE id = " . (int)$user_id . " LIMIT 1"
      );
      if ($userInfoRes && ($userInfo = mysqli_fetch_assoc($userInfoRes))) {
        $recipientEmail = trim((string)($userInfo['email'] ?? ''));
        $recipientName = trim((string)($userInfo['first_name'] ?? '') . ' ' . (string)($userInfo['last_name'] ?? ''));
      }

      $lotLabel = 'N/A';
      if ($lot_id !== null && $lot_id > 0) {
        $lotSql = "SELECT l.block_number, l.lot_number, ll.location_name
                   FROM lots l
                   LEFT JOIN lot_locations ll ON ll.id = l.location_id
                   WHERE l.id = " . (int)$lot_id . "
                   LIMIT 1";
        $lotRes = mysqli_query($conn, $lotSql);
        if ($lotRes && ($lotRow = mysqli_fetch_assoc($lotRes))) {
          $lotLabel = 'Block ' . (string)($lotRow['block_number'] ?? 'N/A') . ', Lot ' . (string)($lotRow['lot_number'] ?? 'N/A');
          $locationName = trim((string)($lotRow['location_name'] ?? ''));
          if ($locationName !== '') {
            $lotLabel .= ' (' . $locationName . ')';
          }
        }
      }

      if ($recipientEmail !== '') {
        $mailSubject = 'Nuevo Puerta Document Release Notice';
        $mailBody = "Hello " . ($recipientName !== '' ? $recipientName : 'Client') . ",\n\n"
          . "Your document has been released and is now available in your dashboard.\n"
          . "Property: {$lotLabel}\n"
          . "Document Type: {$doc_type}\n"
          . "Released On: " . date('F j, Y g:i A') . "\n"
          . "Status: Available for viewing in your dashboard\n\n"
          . "Please keep this email as your transaction record.\n\n"
          . "Thank you,\nNuevo Puerta";

        $emailSent = sendSystemEmail(
          $recipientEmail,
          $recipientName,
          $mailSubject,
          $mailBody,
          $emailError,
          [
            [
              'path' => $destPath,
              'name' => $origName,
            ],
          ]
        );
      } else {
        $emailError = 'No recipient email found for this client account.';
      }

      // Notify the user (best-effort — won't crash if table differs)
      @insertUserNotificationFeed($conn, $user_id, 'New Document Available', "A $doc_type has been uploaded for you by the admin. You can view it in your Documents section.", 'info');

      // Audit (best-effort)
      try { logAudit($conn, $admin_id ?? 0, 'Admin Uploaded Document', "Uploaded $doc_type for user_id=$user_id"); } catch (Throwable $ae) { /* skip if audit_logs differs */ }

      respondJSON([
        'success' => true,
        'message' => 'Document uploaded successfully.',
        'email_sent' => $emailSent,
        'email_error' => $emailSent ? null : ($emailError !== '' ? $emailError : null)
      ]);
    } catch (Throwable $uploadEx) {
      respondJSON(['success' => false, 'error' => 'Server error: ' . $uploadEx->getMessage()]);
    }
  }
  if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch']) && $_GET['fetch'] === 'all_user_documents') {
      if (!tableExists($conn, 'user_documents')) {
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
      }

      // Ensure lot_id column exists
      $conn->query("ALTER TABLE user_documents ADD COLUMN IF NOT EXISTS lot_id INT NULL");

      $docs = [];
      $query = "SELECT d.*, u.first_name, u.last_name, u.email, 'user_documents' AS source
        FROM user_documents d
        LEFT JOIN user_accounts u ON d.user_id = u.id
        WHERE " . getDocumentPendingFilterSql('d') . "
        ORDER BY d.uploaded_at DESC";
      $stmt = $conn->prepare($query);
      $stmt->execute();
      $res = $stmt->get_result();
      while ($row = $res->fetch_assoc()) {
          $docs[] = $row;
      }
      $stmt->close();
      header('Content-Type: application/json');
      echo json_encode($docs);
      exit;
  }

  // Get lots for a user (for document upload)
  if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_user_lots') {
      $user_id = (int)($_GET['user_id'] ?? 0);
      if ($user_id <= 0) {
          header('Content-Type: application/json');
          echo json_encode(['success' => false, 'error' => 'Invalid user']);
          exit;
      }

      $lots = [];
      if (tableExists($conn, 'lots') && columnExists($conn, 'lots', 'owner_id')) {
          try {
          $hasBlockNumber = columnExists($conn, 'lots', 'block_number');
          $hasLotNumber = columnExists($conn, 'lots', 'lot_number');
          $hasLocationId = columnExists($conn, 'lots', 'location_id');
          $hasLotLocations = tableExists($conn, 'lot_locations') && columnExists($conn, 'lot_locations', 'location_name');
          $hasViewings = tableExists($conn, 'viewings') && columnExists($conn, 'viewings', 'lot_id') && columnExists($conn, 'viewings', 'client_email');

          $userEmail = '';
          if (tableExists($conn, 'user_accounts') && columnExists($conn, 'user_accounts', 'email')) {
            $emailStmt = $conn->prepare("SELECT email FROM user_accounts WHERE id = ? LIMIT 1");
            if ($emailStmt) {
              $emailStmt->bind_param('i', $user_id);
              $emailStmt->execute();
              $emailRes = $emailStmt->get_result();
              if ($emailRes && ($emailRow = $emailRes->fetch_assoc())) {
                $userEmail = trim((string)($emailRow['email'] ?? ''));
              }
              $emailStmt->close();
            }
          }

          $selectCols = "l.id";
          if ($hasBlockNumber) {
            $selectCols .= ", l.block_number";
          } else {
            $selectCols .= ", NULL AS block_number";
          }
          if ($hasLotNumber) {
            $selectCols .= ", l.lot_number";
          } else {
            $selectCols .= ", NULL AS lot_number";
          }
          if ($hasLotLocations && $hasLocationId) {
            $selectCols .= ", ll.location_name";
          } else {
            $selectCols .= ", '' AS location_name";
          }

          $sql = "SELECT DISTINCT {$selectCols}
              FROM lots l";

          if ($hasLotLocations && $hasLocationId) {
            $sql .= " LEFT JOIN lot_locations ll ON ll.id = l.location_id";
          }

          if ($hasViewings) {
            $sql .= " LEFT JOIN viewings v ON v.lot_id = l.id";
          }

          $sql .= " WHERE l.owner_id = ?";
          if ($hasViewings && $userEmail !== '') {
            $sql .= " OR LOWER(TRIM(v.client_email)) = LOWER(TRIM(?))";
          }

          $sql .= " ORDER BY l.block_number ASC, l.lot_number ASC, l.id ASC";

              $stmt = $conn->prepare($sql);
              if (!$stmt) {
                  throw new Exception("Prepare failed: " . $conn->error);
              }

          if ($hasViewings && $userEmail !== '') {
            $stmt->bind_param('is', $user_id, $userEmail);
          } else {
            $stmt->bind_param('i', $user_id);
          }

              $stmt->execute();
              $res = $stmt->get_result();
              while ($row = $res->fetch_assoc()) {
                  $lots[] = $row;
              }
              $stmt->close();
          } catch (Exception $e) {
              header('Content-Type: application/json');
              echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
              exit;
          }
      }

      header('Content-Type: application/json');
      echo json_encode(['success' => true, 'lots' => $lots]);
      exit;
  }
  ?>

  <!DOCTYPE html>
  <html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@panzoom/panzoom/dist/panzoom.min.js"></script>
    <script>
      document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
          document.querySelectorAll('.alert.success, .alert.error').forEach(function(el) {
            el.style.display = 'none';
          });
        }, 3000);
      });
    </script>
    <style>
      * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        font-family: 'Segoe UI', sans-serif;
      }

      html,
      body {
        width: 100%;
        max-width: 100%;
        overflow-x: hidden;
      }

      body {
        background-color: #f6f6f6;
        display: block;
        min-height: 100vh;
        overflow-x: hidden;
        overflow-y: auto;
      }

      .sidebar-wrapper {
        position: fixed;
        top: 0;
        left: 0;
        width: 290px;
        height: 100dvh;
        min-height: 100vh;
        display: flex;
        align-items: stretch;
        z-index: 1000;
      }

      /* Unified UI controls: consistent buttons and form fields */
      button, input[type="button"], input[type="submit"], .btn {
        font-family: inherit;
        font-size: 15px;
        padding: 12px 20px;
        border-radius: 6px;
        min-width: 100px;
        box-shadow: none;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        line-height: 1;
      }

      .user-profile {
        display: flex;
        align-items: center;
        gap: 12px;
        background: rgba(255,255,255,0.08); /* translucent box like agent */
        padding: 10px 12px;
        border-radius: 12px;
        width: 220px;
        margin: 0 auto 16px;
        box-shadow: none;
        text-align: left;
      }

      .user-profile img {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        object-fit: cover;
        background-color: transparent;
        flex-shrink: 0;
      }

      .user-details {
        font-size: 14px;
        color: #ffffff;
        line-height: 1.1;
      }

      .user-details div:first-child {
        font-size: 15px;
        font-weight: 600;
        color: #ffffff;
      }

      .user-details div:last-child {
        font-size: 13px;
        color: rgba(255,255,255,0.85);
      }
      input[type="number"], input[type="date"], input[type="datetime-local"],
      select, textarea {
        width: 100%;
        padding: 10px 12px;
        font-size: 14px;
        border: 1px solid #ced4da;
        border-radius: 6px;
        background: #ffffff;
        color: #222222;
        outline: none;
        transition: box-shadow .15s ease, border-color .15s ease;
        box-sizing: border-box;
      }

      input:focus, textarea:focus, select:focus {
        border-color: #7aa97a;
        box-shadow: 0 0 0 3px rgba(42, 139, 73, 0.12);
      }

      .form-group label {
        font-size: 13px;
        margin-bottom: 6px;
        display: block;
        color: #2d482d;
      }

  .sidebar {
      width: 100%;
      background-color: #14532d;
      border-radius: 0px;
      display: flex;
      flex-direction: column;
      padding: 16px 14px;
      height: 100%;
      min-height: 100dvh;
      box-shadow: 0 2px 8px rgba(0,0,0,0.2);
      position: relative;
      overflow-y: hidden;
      overflow-x: hidden;
    }

    /* Scrollbar styling for a cleaner look */
    .sidebar::-webkit-scrollbar {
      width: 6px;
    }
    .sidebar::-webkit-scrollbar-track {
      background: transparent;
    }
    .sidebar::-webkit-scrollbar-thumb {
      background-color: rgba(255, 255, 255, 0.3);
      border-radius: 4px;
    }
    .sidebar::-webkit-scrollbar-thumb:hover {
      background-color: rgba(255, 255, 255, 0.5);
    }

      .user-profile {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        background-color: transparent; /* match agent transparent card */
        padding: 6px 0;
        border-radius: 8px;
        width: 100%;
        margin-bottom: 14px;
        box-shadow: none;
        text-align: center;
      }

      .user-profile img {
        width: 30px;
        height: 30px;
        border-radius: 50%;
        object-fit: cover;
        margin-right: 8px;
        background-color: #d9d9d9;
        margin-bottom: 6px;
      }

      .user-details {
        font-size: 11px;
        color: #ffffff;
        line-height: 1.2;
      }

      .user-details div:first-child {
    font-size: 14px;
    font-weight: 500;
  }

  .user-details div:last-child {
    font-size: 12px;
    color: rgba(255,255,255,0.85);
  }

      .nav {
        display: flex;
        flex-direction: column;
        width: 100%;
        align-items: stretch;
        flex: 1;
        min-height: 0;
        justify-content: space-between;
        gap: 3px;
      }

      /* Make admin nav links match the user dashboard `.nav-link` appearance */
      .nav a {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 9px 12px;
        color: rgba(255,255,255,0.9);
        text-decoration: none;
        transition: background 0.18s, color 0.18s, transform 0.12s;
        border-left: 4px solid transparent;
        font-size: 14px;
        margin: 1px 0;
        border-radius: 6px;
        justify-content: flex-start;
        width: 100%;
        cursor: pointer;
      }

      .nav a:hover,
      .nav a.active {
        background: rgba(255,255,255,0.06);
        color: #fff;
        transform: translateY(-1px);
      }
      
      .nav-icon {
        width: 21px;
        height: 21px;
        margin-right: 5px;
        vertical-align: middle;
        filter: brightness(0) invert(1);
      }

      /* Eye icon using CSS for Manage Viewings */
      .nav-icon-eye {
        background-color: white;
        mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z'/%3E%3C/svg%3E") no-repeat center;
        mask-size: contain;
        -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z'/%3E%3C/svg%3E") no-repeat center;
        -webkit-mask-size: contain;
        filter: none;
      }

      .container {
        margin-left: 290px;
        width: calc(100% - 290px);
        max-width: calc(100% - 290px);
        min-width: 0;
        padding: 32px 28px;
        display: flex;
        flex-direction: column;
        min-height: 100vh;
        height: auto;
        overflow: visible;
      }

      .table-section {
        background: white;
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
      }

      .divider {
        width: 5px;
        background-color: #2D4D26;
        height: calc(100vh - 40px);
        margin-top: 20px;
        border-radius: 5px;
        display: none; /* hide the vertical divider/scroll-like line */
      }

      /* Flip logout icon horizontally */
      .logout-icon {
        display: inline-block;
        transform: scaleX(-1);
        -webkit-transform: scaleX(-1);
      }

      .header {
    display: flex;
    justify-content: flex-start;     /* ← changed to left */
    align-items: center;             /* better vertical alignment */
    gap: 16px;                       /* optional – space between logo/title if any */
    margin-bottom: 32px;
    padding-left: 8px;               /* tiny left breathing room – optional */
  }

      .header h2 {
        color: #2d482d;
        font-size: 30px;
      }

      .header small {
        font-size: 14px;
        color: #555;
      }

      .dashboard-cards {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        margin-bottom: 30px;
      }

      .card {
        background-color: #fff;
        padding: 20px;
        border-radius: 10px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        flex: 1 1 240px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        min-width: 200px;
        position: relative;
      }

      .system-overview-panel {
        padding: 20px;
        background: #f8f9fa;
        border-radius: 8px;
        border: 1px solid #eceff1;
      }

      .system-overview-top-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 16px;
        margin-bottom: 16px;
      }

      .system-overview-top-card {
        text-align: center;
        padding: 16px;
        background: #fff;
        border-radius: 8px;
        border: 1px solid #e0e0e0;
        min-height: 84px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        box-sizing: border-box;
      }

      .system-overview-top-number {
        font-size: 30px;
        line-height: 1.1;
        font-weight: 700;
      }

      .system-overview-top-label {
        font-size: 12px;
        color: #666;
        margin-top: 4px;
      }

      .system-overview-details-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 14px;
        align-items: stretch;
      }

      .system-overview-detail-card {
        padding: 14px;
        background: #fff;
        border-radius: 8px;
        border: 1px solid #e0e0e0;
        min-height: 154px;
        display: flex;
        flex-direction: column;
        justify-content: flex-start;
        box-sizing: border-box;
      }

      .system-overview-block-title {
        font-size: 12px;
        font-weight: 700;
        color: #2d482d;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        margin-bottom: 10px;
      }

      .system-overview-row {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        font-size: 13px;
        color: #444;
        margin-bottom: 6px;
        gap: 10px;
      }

      .system-overview-row:last-child {
        margin-bottom: 0;
      }

      .system-overview-row > strong {
        flex-shrink: 0;
        text-align: right;
      }

      .notifications-container {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 14px;
        min-height: 420px;
        max-height: none;
        overflow-y: auto;
      }

      #section-notifications.active {
        display: flex;
        flex-direction: column;
        min-height: calc(100vh - 120px);
      }

      #section-notifications .table-section {
        flex: 1;
        display: flex;
        flex-direction: column;
      }

      #section-notifications .notifications-container {
        flex: 1;
      }

      .notification-item {
        padding: 12px 14px;
        border-radius: 6px;
        margin-bottom: 10px;
      }

      .notification-item:last-child {
        margin-bottom: 0;
      }

      .notification-item-title {
        display: block;
        margin-bottom: 4px;
      }

      .notification-item-message {
        margin: 0;
      }

      .notification-item-time {
        display: block;
        margin-top: 4px;
        color: #999;
      }

      @media (max-width: 992px) {
        body {
          overflow-x: hidden;
          overflow-y: auto;
        }

        .sidebar-wrapper {
          position: relative;
          width: 100%;
          height: auto;
        }

        .sidebar {
          height: auto;
          max-height: none;
          padding: 14px 12px;
        }

        .nav {
          flex-direction: row;
          flex-wrap: nowrap;
          justify-content: flex-start;
          gap: 8px;
          overflow-x: auto;
          overflow-y: hidden;
          padding-bottom: 6px;
        }

        .nav a {
          width: auto;
          min-width: max-content;
          margin: 0;
          padding: 9px 12px;
          border-left: 0;
        }

        .container {
          margin-left: 0;
          width: 100%;
          max-width: 100%;
          height: auto;
          min-height: calc(100vh - 1px);
          padding: 20px 14px;
        }

        .header h2 {
          font-size: 24px;
        }

        .table-section {
          padding: 14px;
        }

        #section-notifications.active {
          min-height: auto;
        }

        #section-notifications .notifications-container {
          min-height: 280px;
        }
      }

      .card-content {
        display: flex;
        align-items: center;
        justify-content: space-between;
      }

      .card-text {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        padding-left: 10px;
        padding-right: 10px;
      }

      .card-title {
        font-size: 14px;
        font-weight: bold;
        color: #2d482d;
        margin-bottom: 5px;
      }

      .card-subtitle {
        font-size: 12px;
        color: #555;
        margin-bottom: 10px;
      }

      .card-icon {
        font-size: 26px;
        color: #2d482d;
        width: 60px;
        height: 60px;
        object-fit: contain;
        position: absolute;
        top: 50%;
        right: 20px;
        transform: translateY(-50%);
      }

      .card-number {
        font-size: 24px;
        font-weight: bold;
        color: #2d482d;
      }

      .section {
        display: none;
      }

      /* Show active section and animate like user dashboard */
      .section.active {
        display: block;
        animation: fadeIn 0.36s ease;
      }

      @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to   { opacity: 1; transform: translateY(0); }
      }

      .table-section {
        background-color: #fff;
        padding: 20px;
        border-radius: 10px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        width: 100%;
        flex: 1;
        max-width: 100%;
        overflow-x: auto;
      }

      .table-section table {
        width: 100%;
        min-width: 760px;
        border-collapse: collapse;
        table-layout: auto;
      }

      .table-section th,
      .table-section td {
        vertical-align: middle;
        white-space: normal;
        word-break: break-word;
      }

      #lots-table {
        min-width: 1120px;
      }

      #section-lots .table-section {
        padding: 24px;
      }

      #section-lots .table-section table {
        min-width: 1120px;
      }

      #lots-table th:first-child,
      #lots-table td:first-child {
        width: 44px;
        text-align: center;
      }

      #lots-table th:nth-child(2),
      #lots-table td:nth-child(2),
      #lots-table th:nth-child(3),
      #lots-table td:nth-child(3) {
        min-width: 110px;
      }

      #lots-table th:nth-child(4),
      #lots-table td:nth-child(4),
      #lots-table th:nth-child(5),
      #lots-table td:nth-child(5),
      #lots-table th:nth-child(6),
      #lots-table td:nth-child(6) {
        min-width: 140px;
      }

      #lots-table th:nth-child(7),
      #lots-table td:nth-child(7) {
        min-width: 115px;
      }

      #lots-table th:last-child,
      #lots-table td:last-child {
        min-width: 260px;
      }

      #new-row td {
        background: #f8fbf8;
        padding-top: 14px;
        padding-bottom: 14px;
      }

      #new-row input,
      #new-row select {
        width: 100%;
        min-height: 40px;
        padding: 8px 10px;
        border: 1px solid #cfd8d3;
        border-radius: 8px;
        font-size: 14px;
        box-sizing: border-box;
        background: #ffffff;
      }

      #new-row input:focus,
      #new-row select:focus {
        outline: none;
        border-color: #3e5f3e;
        box-shadow: 0 0 0 3px rgba(62, 95, 62, 0.15);
      }

      .new-lot-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
      }

      .new-lot-actions button {
        margin: 0;
        min-width: 96px;
      }

      #section-lots .location-dropdown {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 20px;
        flex-wrap: wrap;
      }

      #section-lots .location-dropdown label {
        min-width: 90px;
      }

      #section-lots .location-dropdown select {
        flex: 1;
        min-width: 280px;
      }

      @media (max-width: 1100px) {
        #section-lots .table-section {
          padding: 18px;
        }

        #lots-table {
          min-width: 980px;
        }

        #section-lots .table-section table {
          min-width: 980px;
        }
      }

      @media (max-width: 768px) {
        #new-row input,
        #new-row select {
          min-height: 38px;
          font-size: 13px;
        }

        .new-lot-actions {
          flex-direction: column;
        }

        .new-lot-actions button {
          width: 100%;
        }
      }

      .lots-action-buttons {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
      }

      .table-section thead {
        background-color: #3e5f3e;
        color: white;
      }

      .table-section th, .table-section td {
        padding: 12px 10px;
        text-align: left;
        border-bottom: 1px solid #ddd;
      }

      .table-section tbody tr:hover {
        background-color: #f1f1f1;
      }

      .table-section thead tr:hover {
        background-color: transparent;
      }

      .table-section button, .btn {
        background-color: #2d482d;
        color: white;
        border: none;
        padding: 10px 18px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        transition: background-color 0.2s ease-in-out;
        margin: 0 2px;
      }

      .table-section button:hover, .btn:hover {
        background-color: #3e5f3e;
      }

      .btn-danger {
        background-color: #dc3545;
      }

      .btn-danger:hover {
        background-color: #c82333;
      }

      .form-group {
        margin-bottom: 15px;
      }

      .form-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: 500;
        color: #2d482d;
      }

      .form-group input, .form-group select {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
      }

      .form-row {
        display: flex;
        gap: 15px;
      }

      .alert {
        padding: 12px;
        border-radius: 6px;
        margin-bottom: 20px;
        font-size: 14px;
      }

      .alert.success {
        background-color: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
      }

      .alert.error {
        background-color: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
      }

      .location-dropdown {
        margin-bottom: 20px;
      }

      .status-badge {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: bold;
        color: white;
      }

      .status-scheduled {
        background-color: #28a745;
      }

      .status-pending, .status-requested {
        background-color: #ffc107;
        color: #212529;
      }

      .status-completed {
        background-color: #17a2b8;
      }

      .status-cancelled {
        background-color: #dc3545;
      }

      #section-viewings .table-section table {
        min-width: 1160px;
      }

      #section-viewings .table-section {
        padding: 24px;
      }

      #section-viewings table {
        table-layout: fixed;
      }

      #section-viewings th,
      #section-viewings td {
        vertical-align: top;
      }

      #section-viewings .table-section th:nth-child(1),
      #section-viewings .table-section td:nth-child(1) {
        width: 15%;
      }

      #section-viewings .table-section th:nth-child(2),
      #section-viewings .table-section td:nth-child(2) {
        width: 17%;
      }

      #section-viewings .table-section th:nth-child(3),
      #section-viewings .table-section td:nth-child(3) {
        width: 13%;
      }

      #section-viewings .table-section th:nth-child(4),
      #section-viewings .table-section td:nth-child(4) {
        width: 28%;
      }

      #section-viewings .table-section th:nth-child(5),
      #section-viewings .table-section td:nth-child(5) {
        width: 11%;
      }

      #section-viewings .table-section th:nth-child(6),
      #section-viewings .table-section td:nth-child(6) {
        width: 10%;
        min-width: 120px;
      }

      #section-viewings .table-section th:nth-child(7),
      #section-viewings .table-section td:nth-child(7) {
        min-width: 220px;
        width: 16%;
      }

      #section-viewings .status-badge {
        white-space: nowrap;
        display: inline-block;
      }

      .viewing-actions {
        display: flex;
        flex-direction: column;
        gap: 10px;
      }

      .viewing-assigned-card {
        background: linear-gradient(180deg, #eef8f1 0%, #e3f2e8 100%);
        border: 1px solid #cde5d5;
        border-radius: 10px;
        padding: 10px 12px;
        display: flex;
        flex-direction: column;
        gap: 6px;
      }

      .viewing-assigned-label {
        font-size: 12px;
        color: #1f5e37;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.02em;
      }

      .viewing-assigned-name {
        font-size: 14px;
        color: #15532c;
        font-weight: 700;
        line-height: 1.3;
      }

      .viewing-client-meta {
        font-size: 12px;
        color: #666;
        line-height: 1.4;
      }

      .viewing-lot-details {
        display: flex;
        flex-direction: column;
        gap: 4px;
        line-height: 1.35;
      }

      .viewing-lot-row {
        display: flex;
        align-items: baseline;
        gap: 6px;
        flex-wrap: wrap;
      }

      .viewing-lot-label {
        color: #46614b;
        font-weight: 700;
        font-size: 12px;
        min-width: 52px;
      }

      .viewing-lot-value {
        color: #223322;
        font-size: 13px;
        font-weight: 600;
      }

      .viewing-date-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 36px;
        padding: 8px 12px;
        border-radius: 999px;
        background: #f4f7f4;
        border: 1px solid #d9e2da;
        color: #244029;
        font-size: 13px;
        font-weight: 600;
        white-space: nowrap;
      }

      .viewing-assignment-form {
        width: 100%;
        display: flex;
        flex-direction: column;
        gap: 8px;
      }

      .viewing-assignment-form select {
        width: 100%;
        min-height: 40px;
        padding: 8px 10px;
        border: 1px solid #cfd8d3;
        border-radius: 8px;
        background: #fff;
        font-size: 13px;
        box-sizing: border-box;
      }

      .viewing-assignment-buttons {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
      }

      .viewing-assignment-buttons .btn-small {
        margin: 0;
      }

      @media (max-width: 1100px) {
        #section-viewings .table-section {
          padding: 18px;
        }

        #section-viewings .table-section table {
          min-width: 1040px;
        }

        .viewing-assignment-buttons {
          flex-direction: column;
        }

        .viewing-assignment-buttons .btn-small {
          width: 100%;
        }
      }

      @media (max-width: 768px) {
        #section-viewings .table-section {
          padding: 16px;
        }

        .viewing-actions {
          gap: 8px;
        }

        .viewing-assigned-card {
          padding: 8px 10px;
        }

        .viewing-lot-details {
          gap: 3px;
        }
      }

      .btn-assign {
        background-color: #28a745;
        color: white;
        border: none;
        padding: 10px 18px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
      }

      .btn-assign:hover {
        background-color: #218838;
      }

      .account-type-nav {
        display: flex;
        gap: 0;
        margin-bottom: 30px;
        border-bottom: 1px solid #e0e0e0;
      }
      
      .account-type-nav a {
        padding: 12px 24px;
        background-color: #f8f9fa;
        color: #666;
        text-decoration: none;
        border: 1px solid #e0e0e0;
        border-bottom: none;
        font-size: 14px;
        font-weight: 500;
        transition: all 0.3s ease;
        position: relative;
      }
      
      .account-type-nav a:first-child {
        border-radius: 8px 0 0 0;
      }
      
      .account-type-nav a:last-child {
        border-radius: 0 8px 0 0;
      }
      
      .account-type-nav a:hover {
        background-color: #e8f5e8;
        color: #2d482d;
        border-color: #c3e6cb;
      }
      
      .account-type-nav a.active {
        background-color: #2d482d;
        color: white;
        border-color: #2d482d;
        z-index: 1;
      }
      
      .account-type-nav a.active:hover {
        background-color: #3e5f3e;
        border-color: #3e5f3e;
      }

      .account-section {
        display: none;
        background: white;
        border: 1px solid #e0e0e0;
        border-top: none;
        border-radius: 0 0 8px 8px;
        padding: 30px;
      }
      
      .account-section.active {
        display: block;
      }

      .form-container {
        background: #fafafa;
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        padding: 30px;
        margin-bottom: 40px;
      }

      .form-title {
        font-size: 20px;
        font-weight: 600;
        color: #333;
        margin-bottom: 25px;
        padding-bottom: 15px;
        border-bottom: 1px solid #e0e0e0;
      }

      .form-section {
        margin-bottom: 25px;
      }

      .form-section-title {
        font-size: 14px;
        font-weight: 600;
        color: #555;
        margin-bottom: 15px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
      }

      .form-group {
        margin-bottom: 20px;
      }

      .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 500;
        color: #333;
        font-size: 14px;
      }

      .form-group input, 
      .form-group select, 
      .form-group textarea {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #ddd;
        border-radius: 6px;
        font-size: 14px;
        background-color: white;
        transition: border-color 0.2s;
      }

      .form-group input:focus, 
      .form-group select:focus, 
      .form-group textarea:focus {
        outline: none;
        border-color: #666;
      }

      .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
      }

      .form-row-three {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 20px;
      }

      .photo-upload-section {
        background: white;
        border: 1px solid #e0e0e0;
        border-radius: 6px;
        padding: 20px;
        text-align: center;
      }

      .photo-preview {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid #e0e0e0;
        margin: 0 auto 15px;
        display: block;
      }

      .photo-placeholder {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        background-color: #f5f5f5;
        border: 2px dashed #ccc;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 15px;
        color: #999;
        font-size: 12px;
      }

      .file-input-wrapper {
        position: relative;
        display: inline-block;
      }

      .file-input-wrapper input[type=file] {
        position: absolute;
        opacity: 0;
        width: 100%;
        height: 100%;
        cursor: pointer;
      }

      .file-input-label {
        background: #f8f9fa;
        border: 1px solid #ddd;
        padding: 8px 16px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 13px;
        color: #666;
        transition: background-color 0.2s;
      }

      .file-input-label:hover {
        background: #e9ecef;
      }

      .availability-toggle {
        display: flex;
        align-items: center;
        gap: 12px;
        background: white;
        border: 1px solid #e0e0e0;
        border-radius: 6px;
        padding: 15px;
      }

      .toggle-switch {
        position: relative;
        width: 50px;
        height: 24px;
      }

      .toggle-switch input {
        opacity: 0;
        width: 0;
        height: 0;
      }

      .slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #ccc;
        transition: .4s;
        border-radius: 24px;
      }

      .slider:before {
        position: absolute;
        content: "";
        height: 18px;
        width: 18px;
        left: 3px;
        bottom: 3px;
        background-color: white;
        transition: .4s;
        border-radius: 50%;
      }

      input:checked + .slider {
        background-color: #333;
      }

      input:checked + .slider:before {
        transform: translateX(26px);
      }

      .location-section {
        background: white;
        border: 1px solid #e0e0e0;
        border-radius: 6px;
        padding: 20px;
      }

      .location-controls {
        display: flex;
        gap: 10px;
        margin-top: 15px;
      }

      .btn-location {
        background: #f8f9fa;
        border: 1px solid #ddd;
        padding: 10px 18px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
        color: #666;
        transition: all 0.2s;
      }

      .btn-location:hover {
        background: #e9ecef;
        border-color: #999;
      }

      .btn-primary {
        background-color: #333;
        color: white;
        border: none;
        padding: 14px 36px;
        border-radius: 6px;
        font-size: 15px;
        font-weight: 500;
        cursor: pointer;
        transition: background-color 0.2s;
      }

      .btn-primary:hover {
        background-color: #555;
      }

      .location-status {
        margin-top: 10px;
        padding: 8px 12px;
        border-radius: 4px;
        font-size: 13px;
        display: none;
      }

      .location-success {
        background-color: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
      }

      .location-error {
        background-color: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
      }

      .accounts-table {
        background: white;
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        overflow: hidden;
      }

      .accounts-table h3 {
        background: #f8f9fa;
        margin: 0;
        padding: 20px;
        border-bottom: 1px solid #e0e0e0;
        font-size: 16px;
        font-weight: 600;
        color: #333;
      }

      .accounts-table table {
        width: 100%;
        border-collapse: collapse;
      }

      .accounts-table th {
        background: #f8f9fa;
        padding: 12px 15px;
        text-align: left;
        font-weight: 500;
        color: #666;
        font-size: 13px;
        border-bottom: 1px solid #e0e0e0;
      }

      .accounts-table td {
        padding: 15px;
        border-bottom: 1px solid #f0f0f0;
        font-size: 14px;
        color: #333;
      }

      .accounts-table tbody tr:hover {
        background-color: #f9f9f9;
      }

      .profile-photo {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        object-fit: cover;
        border: 1px solid #e0e0e0;
      }

      .profile-placeholder {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background-color: #f5f5f5;
        border: 1px solid #e0e0e0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 10px;
        color: #999;
      }

      .status-active {
        background-color: #d4edda;
        color: #155724;
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 500;
      }

      .status-inactive {
        background-color: #f8d7da;
        color: #721c24;
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 500;
      }

      .btn-small {
        background: #f8f9fa;
        border: 1px solid #ddd;
        padding: 6px 12px;
        border-radius: 4px;
        font-size: 12px;
        color: #666;
        cursor: pointer;
        margin-right: 5px;
        transition: all 0.2s;
      }

      .btn-small:hover {
        background: #e9ecef;
        border-color: #999;
      }

      .accounts-table td .btn-small {
        margin: 0;
      }

      .accounts-table td form {
        display: inline-flex;
        margin: 0;
      }

      .accounts-table tbody td:last-child {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: nowrap;
      }

      .btn-danger {
        background: #dc3545;
        color: white;
        border: 1px solid #dc3545;
      }

      .btn-danger:hover {
        background: #c82333;
        border-color: #c82333;
      }

      .payments-toolbar {
        margin: 10px 0 12px;
      }

      .payment-search-input {
        width: 100%;
        max-width: 460px;
        padding: 9px 11px;
        border: 1px solid #cfd8d3;
        border-radius: 6px;
        font-size: 13px;
        color: #2f3b33;
        background: #fff;
      }

      .payment-search-input:focus {
        outline: none;
        border-color: #3e5f3e;
        box-shadow: 0 0 0 2px rgba(62, 95, 62, 0.15);
      }

      .payments-table {
        width: 100%;
        min-width: 1360px;
        table-layout: fixed;
        border-collapse: collapse;
        margin-top: 15px;
        border: 1px solid #dfe6e1;
        border-radius: 8px;
        overflow: hidden;
      }

      .payments-table thead tr {
        background: #f3f7f3;
      }

      .payments-table th {
        padding: 13px 14px;
        text-align: left;
        font-weight: 600;
        color: #2f3b33;
        font-size: 12px;
        border-bottom: 2px solid #d7e2d9;
        white-space: nowrap;
        vertical-align: middle;
      }

      .payments-table td {
        padding: 13px 14px;
        font-size: 13px;
        color: #2f3b33;
        border-bottom: 1px solid #edf2ee;
        vertical-align: middle;
      }

      .payments-table th:nth-child(1),
      .payments-table td:nth-child(1) {
        width: 70px;
        text-align: center;
      }

      .payments-table th:nth-child(2),
      .payments-table td:nth-child(2) {
        width: 86px;
        text-align: center;
      }

      .payments-table th:nth-child(3),
      .payments-table td:nth-child(3) {
        width: 250px;
      }

      .payments-table th:nth-child(4),
      .payments-table td:nth-child(4) {
        width: 170px;
      }

      .payments-table th:nth-child(5),
      .payments-table td:nth-child(5) {
        width: 120px;
      }

      .payments-table th:nth-child(6),
      .payments-table td:nth-child(6),
      .payments-table th:nth-child(7),
      .payments-table td:nth-child(7),
      .payments-table th:nth-child(8),
      .payments-table td:nth-child(8) {
        width: 112px;
      }

      .payments-table th:nth-child(9),
      .payments-table td:nth-child(9) {
        width: 360px;
      }

      /* Override generic accounts-table last-cell flex styling for payment table consistency */
      .payments-table tbody td:last-child {
        display: table-cell;
        align-items: initial;
        gap: 0;
        flex-wrap: nowrap;
      }

      .payments-table tbody tr:nth-child(even) {
        background: #fbfdfb;
      }

      .payments-table td.col-location {
        min-width: 0;
        line-height: 1.35;
        color: #4b5d4f;
        word-break: break-word;
      }

      .payments-table td.col-owner {
        min-width: 0;
        font-weight: 600;
        word-break: break-word;
      }

      .payments-table td.col-actions {
        min-width: 0;
        text-align: center;
        white-space: normal;
      }

      .payments-table tbody tr {
        transition: background 0.2s ease, box-shadow 0.2s ease;
      }

      .payments-table tbody tr:hover {
        background: #f2f8f2;
      }

      .payments-table tbody td:first-child,
      .payments-table tbody td:nth-child(2) {
        font-weight: 700;
        color: #223727;
      }

      .payment-chip,
      .workflow-chip {
        display: inline-block;
        padding: 6px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.04em;
        color: #fff;
        box-shadow: inset 0 -1px 0 rgba(255, 255, 255, 0.12);
        white-space: nowrap;
        text-align: center;
      }

      .workflow-chip {
        min-width: 98px;
        flex: 0 0 auto;
      }

      .payment-chip-na { background: #6b7280; }
      .payment-chip-cash { background: #3e5f3e; }
      .payment-chip-dp { background: #557a46; }
      .payment-chip-paid { background: #2d6a34; }

      .workflow-available { background: #6b7280; }
      .workflow-reservation { background: #b7791f; }
      .workflow-installments { background: #3e5f3e; }
      .workflow-paid { background: #2d6a34; }

      .amount-paid,
      .amount-price,
      .amount-balance {
        text-align: center;
        white-space: nowrap;
      }

      .amount-paid,
      .amount-price {
        font-weight: 600;
      }

      .amount-balance {
        font-weight: 700;
      }

      .amount-balance-due { color: #b7791f; }
      .amount-balance-clear { color: #2d6a34; }

      .workflow-block {
        display: flex;
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
        min-width: 280px;
        padding: 12px;
        border: 1px solid #dde7de;
        border-radius: 14px;
        background: linear-gradient(180deg, #fdfefd 0%, #f5f9f5 100%);
      }

      .workflow-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        background: #ffffff;
        border: 1px solid #dbe6dd;
        border-radius: 10px;
        padding: 8px 10px;
      }

      .workflow-title {
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.3px;
        text-transform: uppercase;
        color: #4a5a4f;
        line-height: 1.45;
        flex: 1 1 auto;
        min-width: 0;
      }

      .workflow-meta {
        font-size: 12px;
        color: #4b5563;
        background: #ffffff;
        border: 1px solid #e1e8e2;
        border-radius: 10px;
        padding: 8px 10px;
        text-align: left;
      }

      .workflow-progress {
        background: linear-gradient(180deg, #f1fbf4 0%, #ebf8ef 100%);
        border-color: #b8e4c5;
      }

      .workflow-progress-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        margin-bottom: 6px;
      }

      .workflow-progress-percent {
        color: #16803d;
        font-weight: 700;
        white-space: nowrap;
      }

      .workflow-progress-track {
        background: #dcfce7;
        border-radius: 999px;
        height: 7px;
        overflow: hidden;
      }

      .workflow-progress-fill {
        background: linear-gradient(90deg, #22c55e 0%, #16a34a 100%);
        height: 100%;
        border-radius: inherit;
        transition: width 0.3s ease;
      }

      .payment-owner-empty {
        color: #6c757d;
        font-style: italic;
      }


      .payment-actions.payment-actions-image-ref {
        display: grid;
        grid-template-columns: 1fr 1fr;
        grid-template-rows: 1fr 1fr;
        gap: 10px 12px;
        justify-items: stretch;
        align-items: stretch;
        width: 100%;
        max-width: 320px;
        margin: 0 auto;
      }

      .payment-action-btn {
        background: #406244;
        color: #fff;
        border: none;
        border-radius: 10px;
        font-size: 14px;
        font-weight: 600;
        padding: 7px 0;
        min-width: 90px;
        min-height: 32px;
        transition: background 0.18s, color 0.18s, box-shadow 0.18s;
        box-shadow: 0 2px 8px rgba(64,98,68,0.10);
        cursor: pointer;
        outline: none;
        letter-spacing: 0.01em;
        margin: 0;
        width: 100%;
        display: inline-block;
      }
      .payment-action-btn:hover:not(:disabled) {
        background: #2d4e2f;
        color: #fff;
      }
      .payment-action-btn:active:not(:disabled) {
        background: #244026;
      }
      .payment-action-btn.payment-action-disabled,
      .payment-action-btn:disabled {
        background: #cfd6d1;
        color: #f8f9fa;
        cursor: not-allowed;
        opacity: 1;
      }

      .payment-actions .btn-small {
        margin: 0;
        min-width: 120px;
        min-height: 40px;
        padding: 0 18px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        line-height: 1.2;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1.5px solid #2f5a32;
        background: #f8f9fa;
        color: #2f5a32;
        box-shadow: 0 2px 8px rgba(47,90,50,0.08);
        transition: all 0.18s;
        letter-spacing: 0.01em;
        cursor: pointer;
        position: relative;
        overflow: hidden;
      }

      .payment-actions .btn-installment {
        background: linear-gradient(90deg, #4caf50 0%, #388e3c 100%);
        color: #fff;
        border-color: #388e3c;
      }
      .payment-actions .btn-installment:hover {
        background: linear-gradient(90deg, #388e3c 0%, #4caf50 100%);
        color: #fff;
        border-color: #2e7031;
      }

      .payment-actions .btn-record-payment {
        background: linear-gradient(90deg, #1976d2 0%, #1565c0 100%);
        color: #fff;
        border-color: #1565c0;
      }
      .payment-actions .btn-record-payment:hover {
        background: linear-gradient(90deg, #1565c0 0%, #1976d2 100%);
        color: #fff;
        border-color: #0d47a1;
      }

      .payment-actions .btn-history {
        background: #fff;
        color: #388e3c;
        border-color: #b2dfdb;
        box-shadow: none;
      }
      .payment-actions .btn-history:hover {
        background: #e0f2f1;
        color: #14532d;
        border-color: #80cbc4;
      }

      .payment-actions .btn-turnover {
        background: linear-gradient(90deg, #ffb300 0%, #ffa000 100%);
        color: #fff;
        border-color: #ffa000;
      }
      .payment-actions .btn-turnover:hover {
        background: linear-gradient(90deg, #ffa000 0%, #ffb300 100%);
        color: #fff;
        border-color: #ff6f00;
      }

      .payment-action-disabled,
      .payment-actions .btn-small:disabled {
        opacity: 0.55;
        pointer-events: none;
        filter: grayscale(0.2);
      }

      .payment-action-disabled {
        opacity: 0.55;
        cursor: not-allowed;
        pointer-events: none;
        background: #95a59a !important;
        border-color: #95a59a !important;
      }

      @media (max-width: 960px) {
        .payments-toolbar {
          flex-direction: column;
          align-items: stretch;
        }

        .payment-search-wrap {
          flex-basis: auto;
          min-width: 0;
        }
      }

      @media (max-width: 560px) {
        .payments-toolbar {
          padding: 20px 18px 16px;
        }

        .payments-toolbar-copy h3 {
          font-size: 20px;
        }

        .payments-table-shell {
          padding: 16px;
        }

        .payment-actions {
          grid-template-columns: 1fr;
        }

        .payment-actions .action-turnover {
          grid-column: auto;
        }

        .payment-actions .action-history {
          grid-column: auto;
        }

        .workflow-block {
          min-width: 0;
        }
      }

      .payments-toolbar-copy .payments-eyebrow {
        display: block;
        font-weight: 700;
      }

      .payments-toolbar-copy h3 {
        font-weight: 500;
      }

      .payment-btn {
        padding: 10px 14px;
        border-radius: 8px;
        border: 1px solid #2f5a32;
        background: #3e5f3e;
        color: #fff;
        cursor: pointer;
        font-weight: 700;
        letter-spacing: 0.2px;
        box-shadow: 0 6px 14px rgba(47, 90, 50, 0.18);
        transition: transform 0.14s ease, box-shadow 0.14s ease, background-color 0.14s ease, border-color 0.14s ease;
      }

      .payment-btn:hover {
        background: #2f4e2f;
        border-color: #274427;
        transform: translateY(-1px);
        box-shadow: 0 10px 18px rgba(39, 68, 39, 0.22);
      }

      .payment-btn:active {
        transform: translateY(0);
        box-shadow: 0 3px 8px rgba(39, 68, 39, 0.2);
      }

      .payment-btn:focus-visible {
        outline: 3px solid rgba(62, 95, 62, 0.22);
        outline-offset: 2px;
      }

      .payment-btn-secondary {
        background: #557a46;
        border-color: #486b3c;
        box-shadow: none;
        font-weight: 600;
      }

      .payment-btn-secondary:hover {
        background: #47663a;
        border-color: #3f5a33;
        transform: none;
        box-shadow: none;
      }

      .payment-btn-primary {
        min-width: 138px;
      }

      .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: #666;
        background: #f9f9f9;
        border-radius: 8px;
        margin: 20px 0;
      }

      .lot-action-btn {
        border: none;
        border-radius: 6px;
        padding: 6px 12px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: transform 0.12s ease, box-shadow 0.2s ease, opacity 0.2s ease;
      }

      .lot-action-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 3px 10px rgba(0, 0, 0, 0.15);
      }

      .lot-action-btn-blueprint {
        background: #0d6efd;
        color: #fff;
      }

      .lot-action-btn-edit {
        background: #2196f3;
        color: #fff;
      }

      .lot-action-btn-delete {
        background: #dc3545;
        color: #fff;
      }

      .pin-modal-overlay {
        display: none;
        position: fixed;
        z-index: 3000;
        inset: 0;
        background: rgba(0, 0, 0, 0.7);
        justify-content: center;
        align-items: center;
        overflow: auto;
      }

      .pin-modal-card {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 20px 45px rgba(0, 0, 0, 0.35);
        width: 95%;
        max-width: 1040px;
        max-height: 92vh;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
      }

      .pin-modal-header {
        padding: 18px 24px;
        background: linear-gradient(90deg, #223b22, #2d482d);
        color: #fff;
        border-radius: 12px 12px 0 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
      }

      .pin-modal-title {
        margin: 0;
        font-size: 18px;
        font-weight: 700;
      }

      .pin-modal-close {
        font-size: 28px;
        cursor: pointer;
        color: #fff;
        font-weight: 700;
        line-height: 1;
        opacity: 0.95;
      }

      .pin-modal-close:hover {
        opacity: 1;
      }

      .pin-modal-content {
        padding: 20px 24px;
        display: flex;
        flex-direction: column;
        gap: 14px;
      }

      .pin-status-row {
        display: flex;
        gap: 12px;
        justify-content: center;
        flex-wrap: wrap;
      }

      .pin-status-btn {
        padding: 11px 20px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 600;
        background: #fff;
        transition: all 0.2s ease;
      }

      .pin-status-btn:hover {
        transform: translateY(-1px);
      }

      .pin-status-btn-available {
        border: 2px solid #28a745;
        color: #28a745;
      }

      .pin-status-btn-reserved {
        border: 2px solid #ffc107;
        color: #9a7000;
      }

      .pin-status-btn-sold {
        border: 2px solid #dc3545;
        color: #dc3545;
      }

      .pin-modal-help {
        background: #2f3136;
        color: #fff;
        padding: 10px 14px;
        border-radius: 8px;
        font-size: 13px;
      }

      .blueprint-stage {
        position: relative;
        background: linear-gradient(135deg, #f5f7fa, #eef1f4);
        border: 1px solid #d9e0e6;
        border-radius: 10px;
        overflow: hidden;
        min-height: 420px;
        display: flex;
        justify-content: center;
        align-items: center;
      }

      .blueprint-wrapper {
        position: relative;
        display: inline-block;
      }

      #blueprintImage {
        max-width: 100%;
        max-height: 72vh;
        object-fit: contain;
        display: block;
      }

      #blueprintCanvas {
        position: absolute;
        top: 0;
        left: 0;
        cursor: crosshair;
        display: none;
        pointer-events: auto;
      }

      .pin-modal-footer {
        padding: 18px 24px;
        background: #f9fafb;
        border-top: 1px solid #e9ecef;
        border-radius: 0 0 12px 12px;
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        flex-wrap: wrap;
      }

      .pin-modal-footer-btn {
        border: none;
        border-radius: 8px;
        padding: 10px 18px;
        color: #fff;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
      }

      .pin-modal-footer-btn-draw { background: #28a745; }
      .pin-modal-footer-btn-cancel { background: #e0e0e0; color: #333; }
      .pin-modal-footer-btn-save { background: #2d482d; }

      @media (max-width: 768px) {
        .pin-modal-card {
          width: 98%;
          max-height: 96vh;
        }

        .pin-modal-content,
        .pin-modal-header,
        .pin-modal-footer {
          padding: 14px;
        }

        .blueprint-stage {
          min-height: 320px;
        }
      }

      textarea {
        min-height: 80px;
        resize: vertical;
      }

      .status-reschedule_requested {
    background-color: #f4d03f;
    color: #234;
  }
    </style>
  </head>
  <body onload="loadLocations()">
    <div class="sidebar-wrapper">
      <div class="sidebar">
      <div style="display:flex;align-items:center;gap:12px;margin:8px 6px 18px;">
    <img src="assets/a.png" alt="Logo" class="profile-pic" style="width:52px;height:52px;border-radius:50%;object-fit:cover;background-color:transparent;">
    <div style="line-height:1.1;">
      <h2 style="font-weight:700;font-size:1.06rem;letter-spacing:0.7px;line-height:1;color:white;margin:0;">NUEVO PUERTA</h2>
      <span style="font-size:0.86rem;letter-spacing:0.4px;color:white;opacity:0.9;line-height:1;">REAL ESTATE</span>
    </div>
  </div>

        <div style="display:flex;align-items:center;background:rgba(255,255,255,0.08);border-radius:12px;padding:10px 12px;margin:0 0 14px;width:100%;">
          <div style="margin-right:12px; flex-shrink:0;">
            <img src="assets/s.png" alt="User Image" style="width:36px; height:36px; border-radius:50%; object-fit:cover; display:block;" />
          </div>
          <div style="line-height:1.1;">
            <div style="font-weight:600; font-size:15px; color:#ffffff;">
              <?php echo htmlspecialchars($admin_name); ?>
            </div>
            <div style="font-size:13px; color:rgba(255,255,255,0.85);">
              <?php echo htmlspecialchars($admin_role); ?>
            </div>
          </div>
        </div>

        <div class="nav">
          <a data-target="section-dashboard" class="active">
            <img src="assets/mdi_home.png" alt="Home Icon" class="nav-icon">
            <span>Dashboard</span>
          </a>
          <a data-target="section-accounts">
            <img src="assets/mdi_user.png" alt="Accounts Icon" class="nav-icon">
            <span>Manage Accounts</span>
          </a>
          <a data-target="section-lots">
            <img src="assets/lotpinicon.png" alt="Lot Icon" class="nav-icon">
            <span>Manage Lots</span>
          </a>
          <a data-target="section-viewings">
            <div class="nav-icon nav-icon-eye"></div>
            <span>Manage Viewings</span>
          </a>

          <a data-target="section-notifications" style="position: relative;">
            <svg width="24" height="24" viewBox="0 0 24 24" class="nav-icon" style="fill:white;">
              <path d="M12 22a2 2 0 0 0 2-2h-4a2 2 0 0 0 2 2zm6-6V11a6 6 0 0 0-5-5.91V4a1 1 0 1 0-2 0v1.09A6 6 0 0 0 6 11v5l-2 2v1h16v-1l-2-2z"/>
            </svg>
            <span>Notifications</span>
            <?php if ($unreadNotificationsCount > 0): ?>
              <span style="position: absolute; top: 8px; right: 8px; background-color: #ef4444; color: white; font-size: 11px; font-weight: bold; padding: 2px 6px; border-radius: 50%; min-width: 20px; text-align: center;">
                <?php echo $unreadNotificationsCount; ?>
              </span>
            <?php endif; ?>
          </a>
          <a data-target="section-audit-logs">
            <svg width="24" height="24" viewBox="0 0 24 24" class="nav-icon" style="fill:white;">
              <path d="M9 2h6a2 2 0 0 1 2 2v2h2a1 1 0 0 1 1 1v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a1 1 0 0 1 1-1h2V4a2 2 0 0 1 2-2zm0 4h6V4H9v2zm-1 5h8a1 1 0 1 0 0-2H8a1 1 0 0 0 0 2zm0 4h8a1 1 0 1 0 0-2H8a1 1 0 0 0 0 2z"/>
            </svg>
            <span>Audit Logs</span>
          </a>
          <a data-target="section-documents">
            <svg width="24" height="24" viewBox="0 0 24 24" class="nav-icon" style="fill:white;">
              <path d="M6 2h8l4 4v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm7 1.5V7h3.5L13 3.5zM8 11h8a1 1 0 1 0 0-2H8a1 1 0 0 0 0 2zm0 4h8a1 1 0 1 0 0-2H8a1 1 0 0 0 0 2z"/>
            </svg>
            <span>Documents</span>
          </a>
          <a data-target="section-payments">
            <svg width="24" height="24" viewBox="0 0 24 24" class="nav-icon" style="fill:white;">
              <path d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 14H4V10h16v8zm0-10H4V6h16v2z"/>
            </svg>
            <span>Payments</span>
          </a>
          <a data-target="section-reports">
            <svg width="24" height="24" viewBox="0 0 24 24" class="nav-icon" style="fill:white;">
              <path d="M5 3h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-5l-4 3v-3H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zm2 4v2h10V7H7zm0 4v2h10v-2H7z"/>
            </svg>
            <span>Reports</span>
          </a>
          <a data-target="section-lot-owners">
            <svg width="24" height="24" viewBox="0 0 24 24" class="nav-icon" style="fill:white;">
              <path d="M12 12c2.7 0 5-2.3 5-5s-2.3-5-5-5-5 2.3-5 5 2.3 5 5 5zm0 2c-3.3 0-10 1.7-10 5v3h20v-3c0-3.3-6.7-5-10-5z"/>
            </svg>
            <span>Lot Owners</span>
          </a>

          <a data-target="section-lot-status" style="position: relative;">
            <svg width="24" height="24" viewBox="0 0 24 24" class="nav-icon" style="fill:white;">
              <path d="M4 6h16v2H4V6zm0 4h16v2H4v-2zm0 4h16v6H4v-6zm6 4h4v2h-4v-2z"/>
            </svg>
            <span>Lot Status</span>
            <?php if ($unreadLotStatusCount > 0): ?>
              <span style="position: absolute; top: 8px; right: 8px; background-color: #ef4444; color: white; font-size: 11px; font-weight: bold; padding: 2px 6px; border-radius: 50%; min-width: 20px; text-align: center;">
                <?php echo $unreadLotStatusCount; ?>
              </span>
            <?php endif; ?>
          </a>

          <a href="#" onclick="confirmLogout()">
            <img src="assets/ic_baseline-logout.png" alt="Logout Icon" class="nav-icon logout-icon">
            <span>Logout</span>
          </a>
        </div>
      </div>
    </div>

    <div class="divider"></div>

    <div class="container">
      <div id="section-dashboard" class="section">
        <div class="header">
          <div>
            <h2>Welcome, <?php echo htmlspecialchars($admin_name); ?></h2>
            <small>Admin Dashboard. Monitor and manage system activities.</small>
          </div>
        </div>

        <div class="dashboard-cards">
          <div class="card">
            <div class="card-content">
              <div class="card-text">
                <div class="card-title">CLIENTS</div>
                <div class="card-subtitle">Number of Clients</div>
                <div class="card-number"><?php echo number_format($dashboard_stats['clients']); ?></div>
              </div>
              <img src="assets/mdi_people.png" alt="Clients Icon" class="card-icon">
            </div>
          </div>

          <div class="card">
            <div class="card-content">
              <div class="card-text">
                <div class="card-title">LOTS</div>
                <div class="card-subtitle">Total Number of Lots</div>
                <div class="card-number"><?php echo number_format($dashboard_stats['lots']); ?></div>
              </div>
              <img src="assets/ooui_map-pin.png" alt="Lots Icon" class="card-icon">
            </div>
          </div>

          <div class="card">
            <div class="card-content">
              <div class="card-text">
                <div class="card-title">AGENTS</div>
                <div class="card-subtitle">Available Agents</div>
                <div class="card-number"><?php echo number_format($dashboard_stats['agents']); ?></div>
              </div>
              <img src="assets/mdi_face-agent.png" alt="Agents Icon" class="card-icon">
            </div>
          </div>
        </div>

        <div class="table-section">
          <h2 style="margin-top: 12px; color: #2d482d;">System Overview</h2>
          <div class="system-overview-panel">
            <?php
              $normalizedStatusSql = "CASE
                WHEN l.status = 'Installments' THEN 'Installment'
                WHEN l.status = 'Sold' THEN 'Paid'
                WHEN l.status = '' OR l.status IS NULL THEN 'Available'
                ELSE l.status
              END";

              $pendingViewingsQuery = "SELECT COUNT(*) as total FROM viewings WHERE LOWER(TRIM(status)) IN ('pending', 'requested')";
              $pendingViewingsResult = mysqli_query($conn, $pendingViewingsQuery);
              $pendingViewings = $pendingViewingsResult ? (int)(mysqli_fetch_assoc($pendingViewingsResult)['total'] ?? 0) : 0;

              $approvedScheduledViewingsQuery = "SELECT COUNT(*) as total FROM viewings WHERE LOWER(TRIM(status)) IN ('approved', 'scheduled')";
              $approvedScheduledViewingsResult = mysqli_query($conn, $approvedScheduledViewingsQuery);
              $approvedScheduledViewings = $approvedScheduledViewingsResult ? (int)(mysqli_fetch_assoc($approvedScheduledViewingsResult)['total'] ?? 0) : 0;

              $availableLotsQuery = "SELECT COUNT(*) as total FROM lots l WHERE {$normalizedStatusSql} = 'Available'";
              $availableLotsResult = mysqli_query($conn, $availableLotsQuery);
              $availableLots = $availableLotsResult ? (int)(mysqli_fetch_assoc($availableLotsResult)['total'] ?? 0) : 0;

              $reservedLotsQuery = "SELECT COUNT(*) as total FROM lots l WHERE {$normalizedStatusSql} = 'Reserved'";
              $reservedLotsResult = mysqli_query($conn, $reservedLotsQuery);
              $reservedLots = $reservedLotsResult ? (int)(mysqli_fetch_assoc($reservedLotsResult)['total'] ?? 0) : 0;

              $fullyPaidLotsQuery = "
                SELECT COUNT(*) as total
                FROM lots l
                LEFT JOIN (
                  SELECT lot_id, IFNULL(SUM(amount), 0) AS total_paid
                  FROM lot_payment_transactions
                  GROUP BY lot_id
                ) tx ON tx.lot_id = l.id
                WHERE (
                  {$normalizedStatusSql} = 'Paid'
                  OR l.payment_type = 'Fully Paid'
                  OR (IFNULL(tx.total_paid, 0) >= IFNULL(l.lot_price, 0) AND IFNULL(l.lot_price, 0) > 0)
                )
              ";
              $fullyPaidLotsResult = mysqli_query($conn, $fullyPaidLotsQuery);
              $fullyPaidLots = $fullyPaidLotsResult ? (int)(mysqli_fetch_assoc($fullyPaidLotsResult)['total'] ?? 0) : 0;

              $installmentLotsQuery = "
                SELECT COUNT(*) as total
                FROM lots l
                LEFT JOIN (
                  SELECT lot_id, IFNULL(SUM(amount), 0) AS total_paid
                  FROM lot_payment_transactions
                  GROUP BY lot_id
                ) tx ON tx.lot_id = l.id
                WHERE (
                  {$normalizedStatusSql} = 'Installment'
                  OR l.payment_type = 'Down Payment'
                  OR (IFNULL(tx.total_paid, 0) > 0 AND IFNULL(tx.total_paid, 0) < IFNULL(l.lot_price, 0) AND IFNULL(l.lot_price, 0) > 0)
                )
                AND NOT (
                  {$normalizedStatusSql} = 'Paid'
                  OR l.payment_type = 'Fully Paid'
                  OR (IFNULL(tx.total_paid, 0) >= IFNULL(l.lot_price, 0) AND IFNULL(l.lot_price, 0) > 0)
                )
              ";
              $installmentLotsResult = mysqli_query($conn, $installmentLotsQuery);
              $installmentLots = $installmentLotsResult ? (int)(mysqli_fetch_assoc($installmentLotsResult)['total'] ?? 0) : 0;

              $installmentOverview = buildInstallmentDueOverview($conn, null);
              $overdueInstallments = (int)($installmentOverview['overdue_installments'] ?? 0);
              $dueThisWeek = (int)($installmentOverview['due_this_week'] ?? 0);
              $missedThisMonth = (int)($installmentOverview['missed_this_month'] ?? 0);
              $collectedThisMonth = (float)($installmentOverview['collected_this_month'] ?? 0);
              $expectedThisMonth = (float)($installmentOverview['expected_this_month'] ?? 0);
              $collectionRate = $expectedThisMonth > 0 ? round(($collectedThisMonth / $expectedThisMonth) * 100, 1) : 0;
            ?>

            <div class="system-overview-top-grid">
              <div class="system-overview-top-card">
                <div class="system-overview-top-number" style="color: #28a745;"><?php echo $pendingViewings; ?></div>
                <div class="system-overview-top-label">Pending Viewings</div>
              </div>

              <div class="system-overview-top-card">
                <div class="system-overview-top-number" style="color: #17a2b8;"><?php echo $availableLots; ?></div>
                <div class="system-overview-top-label">Available Lots</div>
              </div>

              <div class="system-overview-top-card">
                <div class="system-overview-top-number" style="color: #dc3545;"><?php echo $fullyPaidLots; ?></div>
                <div class="system-overview-top-label">Fully Paid Lots</div>
              </div>

              <div class="system-overview-top-card">
                <div class="system-overview-top-number" style="color: #6f42c1;">
                  <?php echo ($dashboard_stats['lots'] > 0) ? round(($fullyPaidLots / $dashboard_stats['lots']) * 100, 1) : 0; ?>%
                </div>
                <div class="system-overview-top-label">Sales Rate</div>
              </div>
            </div>

            <div class="system-overview-details-grid">
              <div class="system-overview-detail-card">
                <div class="system-overview-block-title">Reservation Pipeline</div>
                <div class="system-overview-row"><span>Pending Reservations</span><strong><?php echo $pendingViewings; ?></strong></div>
                <div class="system-overview-row"><span>Approved/Scheduled</span><strong><?php echo $approvedScheduledViewings; ?></strong></div>
                <div class="system-overview-row"><span>Reserved Lots</span><strong><?php echo $reservedLots; ?></strong></div>
              </div>

              <div class="system-overview-detail-card">
                <div class="system-overview-block-title">Payment Risk</div>
                <div class="system-overview-row"><span>Overdue Installments</span><strong style="color:#dc3545;"><?php echo $overdueInstallments; ?></strong></div>
                <div class="system-overview-row"><span>Due This Week</span><strong style="color:#d97706;"><?php echo $dueThisWeek; ?></strong></div>
                <div class="system-overview-row"><span>Missed This Month</span><strong><?php echo $missedThisMonth; ?></strong></div>
              </div>

              <div class="system-overview-detail-card">
                <div class="system-overview-block-title">Revenue Snapshot</div>
                <div class="system-overview-row"><span>Collected This Month</span><strong>PHP <?php echo number_format($collectedThisMonth, 2); ?></strong></div>
                <div class="system-overview-row"><span>Expected This Month</span><strong>PHP <?php echo number_format($expectedThisMonth, 2); ?></strong></div>
                <div class="system-overview-row"><span>Collection Rate</span><strong><?php echo $collectionRate; ?>%</strong></div>
              </div>

              <div class="system-overview-detail-card">
                <div class="system-overview-block-title">Inventory Health</div>
                <div class="system-overview-row"><span>Available</span><strong><?php echo $availableLots; ?></strong></div>
                <div class="system-overview-row"><span>Reserved</span><strong><?php echo $reservedLots; ?></strong></div>
                <div class="system-overview-row"><span>Installment</span><strong><?php echo $installmentLots; ?></strong></div>
                <div class="system-overview-row"><span>Fully Paid</span><strong><?php echo $fullyPaidLots; ?></strong></div>
              </div>
            </div>
          </div>

          <h2 style="margin-top: 26px;">Recent Activity</h2>
          
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 20px;">
            <div>
              <h3 style="color: #2d482d; margin-bottom: 15px; font-size: 16px;">Recent User Registrations</h3>
              <?php
              $recentUsersQuery = "SELECT first_name, middle_name, last_name, email, created_at 
                                  FROM user_accounts 
                                  ORDER BY created_at DESC 
                                  LIMIT 5";
              $recentUsersResult = mysqli_query($conn, $recentUsersQuery);
              
              if ($recentUsersResult && mysqli_num_rows($recentUsersResult) > 0):
              ?>
                <div style="background: #f8f9fa; border-radius: 8px; padding: 15px;">
                  <?php while ($user = mysqli_fetch_assoc($recentUsersResult)): ?>
                    <div style="padding: 8px 0; border-bottom: 1px solid #e0e0e0;">
                      <div style="font-weight: 500; color: #333;">
                        <?php echo htmlspecialchars($user['first_name'] . ' ' . ($user['middle_name'] ? $user['middle_name'] . ' ' : '') . $user['last_name']); ?>
                      </div>
                      <div style="font-size: 12px; color: #666;">
                        <?php echo htmlspecialchars($user['email']); ?> • 
                        <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                      </div>
                    </div>
                  <?php endwhile; ?>
                </div>
              <?php else: ?>
                <div style="text-align: center; color: #666; padding: 20px;">
                  No recent user registrations
                </div>
              <?php endif; ?>
            </div>

            <div>
               <h3 style="color: #2d482d; margin-bottom: 15px; font-size: 16px;">Recent Viewing Requests</h3>
              <?php
              $recentViewingsQuery = "SELECT v.client_first_name, v.client_last_name, v.status, v.created_at, 
                                            ll.location_name, l.block_number, l.lot_number
                                    FROM viewings v
                                    LEFT JOIN lot_locations ll ON v.location_id = ll.id
                                    LEFT JOIN lots l ON v.lot_id = l.id
                                    ORDER BY v.created_at DESC 
                                    LIMIT 5";
              $recentViewingsResult = mysqli_query($conn, $recentViewingsQuery);
              
              if ($recentViewingsResult && mysqli_num_rows($recentViewingsResult) > 0):
              ?>
                <div style="background: #f8f9fa; border-radius: 8px; padding: 15px;">
                  <?php while ($viewing = mysqli_fetch_assoc($recentViewingsResult)): ?>
                    <div style="padding: 8px 0; border-bottom: 1px solid #e0e0e0;">
                      <div style="font-weight: 500; color: #333;">
                        <?php echo htmlspecialchars($viewing['client_first_name'] . ' ' . $viewing['client_last_name']); ?>
                      </div>
                      <div style="font-size: 12px; color: #666;">
                        <?php echo htmlspecialchars($viewing['location_name']); ?> - Block <?php echo htmlspecialchars($viewing['block_number']); ?>, Lot <?php echo htmlspecialchars($viewing['lot_number']); ?>
                      </div>
                      <div style="font-size: 11px; color: #999;">
                        <span class="status-badge status-<?php echo strtolower($viewing['status']); ?>" style="font-size: 10px; padding: 2px 6px;">
                          <?php echo htmlspecialchars(ucfirst($viewing['status'])); ?>
                        </span>
                        • <?php echo date('M d, Y', strtotime($viewing['created_at'])); ?>
                      </div>
                    </div>
                  <?php endwhile; ?>
                </div>
              <?php else: ?>
                <div style="text-align: center; color: #666; padding: 20px;">
                  No recent viewing requests
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

    <div id="section-accounts" class="section hidden">
    <div class="header">
      <div>
        <h2>Account Management</h2>
        <small>Create, edit, and manage different types of accounts</small>
      </div>
    </div>

    <div class="table-section">
      <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert success"><?php echo $_SESSION['success_message']; ?></div>
        <?php unset($_SESSION['success_message']); ?>
      <?php endif; ?>

      <?php if (isset($error_message)): ?>
        <div class="alert error"><?php echo $error_message; ?></div>
      <?php endif; ?>

      <div class="account-type-nav">
        <a href="#" onclick="showAccountType('admin')" id="admin-tab" class="active">Admin Accounts</a>
        <a href="#" onclick="showAccountType('agent')" id="agent-tab">Agent Accounts</a>
        <a href="#" onclick="showAccountType('user')" id="user-tab">User Accounts</a>
      </div>

  <div id="admin-accounts" class="account-section active">
    <div class="form-container">
      <div class="form-title">Add New Admin Account</div>
      
      <form method="POST" enctype="multipart/form-data" id="admin-account-form">
        <input type="hidden" name="account_action" value="add">
        
        <div class="form-section">
          <div class="form-section-title">Personal Information</div>

          <div class="form-row-three">
            <div class="form-group">
              <label for="admin_first_name">First Name</label>
              <input type="text" id="admin_first_name" name="first_name" required>
            </div>
            <div class="form-group">
              <label for="admin_middle_name">Middle Name (Optional)</label>
              <input type="text" id="admin_middle_name" name="middle_name">
            </div>
            <div class="form-group">
              <label for="admin_last_name">Last Name</label>
              <input type="text" id="admin_last_name" name="last_name" required>
            </div>
          </div>

          <div class="form-group">
            <label for="admin_username">Username</label>
            <input type="text" id="admin_username" name="username" required>
          </div>
        </div>

        <div class="form-section">
          <div class="form-section-title">Contact Information</div>
          <div class="form-row">
            <div class="form-group">
              <label for="email">Email</label>
              <input type="email" id="email" name="email" required>
            </div>
            <div class="form-group">
              <label for="phone">Phone</label>
              <input type="tel" id="phone" name="phone" required>
            </div>
          </div>
          <div class="form-group">
            <label for="address">Address</label>
            <textarea id="address" name="address" required></textarea>
          </div>
        </div>

        <div class="form-section">
          <div class="form-section-title">Profile Photo (Optional)</div>
          <div class="photo-upload-section">
            <div class="photo-placeholder" id="admin-photo-preview">
              No Photo
            </div>
            <div class="file-input-wrapper">
              <input type="file" id="admin_photo" name="photo" accept="image/*"
                    onchange="previewPhoto(this, 'admin-photo-preview')">
              <label for="admin_photo" class="file-input-label">Choose Photo</label>
            </div>
            <div style="font-size: 12px; color: #999; margin-top: 8px;">
              JPG, PNG, or GIF (Max 5MB) — Optional
            </div>
          </div>
        </div>

        <div class="form-section">
          <div class="form-section-title">Account Security</div>

          <div class="form-row">
            <div class="form-group">
              <label for="admin_password">Password</label>
              <input type="password" id="admin_password" name="password" required>
            </div>

            <div class="form-group">
              <label for="admin_confirm_password">Confirm Password</label>
              <input type="password" id="admin_confirm_password" name="confirm_password" required>
              <small id="admin-password-error"
                    style="color:#dc3545;display:none;font-size:13px;">
                Passwords do not match.
              </small>
            </div>
          </div>
        </div>

        <button type="submit" class="btn-primary">Create Admin Account</button>
        <button type="button" class="btn" style="background:#e0e0e0;color:#333;border:1px solid #ccc;" onclick="resetForm('admin-account-form')">Cancel</button>
      </form>
    </div>

    <div class="accounts-table">
      <h3>Existing Admin Accounts</h3>

      <?php if (empty($adminAccounts)): ?>
        <div class="empty-state" style="
          background:#fafafa;
          padding:40px;
          text-align:center;
          border-radius:8px;
          font-size:18px;
          color:#666;
        ">
          <p>No admin accounts found in the database.</p>
        </div>
      <?php else: ?>
        <table style="width:100%; border-collapse:collapse;">
          <thead>
            <tr style="background:#14532d; color:#fff;">
              <th style="padding:12px 10px;">Name</th>
              <th style="padding:12px 10px; min-width:120px; word-break:break-all;">Username</th>
              <th style="padding:12px 10px;">Email</th>
              <th style="padding:12px 10px;">Mobile</th>
              <th style="padding:12px 10px;">Address</th>
              <th style="padding:12px 10px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($adminAccounts as $account): ?>
              <tr style="border-bottom:1px solid #eee; background:#fff;">
                <td style="padding:10px 8px;"><strong><?php echo htmlspecialchars(trim(($account['first_name'] ?? '') . ' ' . ($account['middle_name'] ?? '') . ' ' . ($account['last_name'] ?? '')) ?: 'N/A'); ?></strong></td>
                <td style="padding:10px 8px;"><?php echo htmlspecialchars($account['username'] ?? ''); ?></td>
                <td style="padding:10px 8px;"><?php echo htmlspecialchars($account['email'] ?? ''); ?></td>
                <td style="padding:10px 8px;"><?php echo htmlspecialchars($account['phone'] ?? ''); ?></td>
                <td style="padding:10px 8px;"><?php echo htmlspecialchars($account['address'] ?? ''); ?></td>
                <td style="padding:10px 8px;">
                  <button onclick="viewProfile(<?php echo (int)$account['id']; ?>, 'admin')" class="btn-small" style="padding:10px 16px; font-size:13px; margin-right:3px;">View</button>
                  <button onclick="editAccount(<?php echo (int)$account['id']; ?>, 'admin')" class="btn-small lot-action-btn-edit" style="padding:10px 16px; font-size:13px; margin-right:3px; background:#2196f3; color:#fff; border:1px solid #2196f3;">Edit</button>
                  <button onclick="deleteAdmin(<?php echo (int)$account['id']; ?>)" class="btn-small btn-danger" style="padding:10px 16px; font-size:13px; background:#dc3545; color:#fff; border:1px solid #dc3545;">Delete</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>



    <div id="agent-accounts" class="account-section">
    <div class="form-container">
      <div class="form-title">Create Agent Account</div>
      
      <form method="POST" enctype="multipart/form-data" id="agent-account-form">
        <input type="hidden" name="agent_action" value="add">
        
        <div class="form-section">
          <div class="form-section-title">Personal Information</div>
          <div class="form-row-three">
            <div class="form-group">
              <label for="agent_first_name">First Name</label>
              <input type="text" id="agent_first_name" name="first_name" required>
            </div>
            <div class="form-group">
              <label for="agent_middle_name">Middle Name (Optional)</label>
              <input type="text" id="agent_middle_name" name="middle_name">
            </div>
            <div class="form-group">
              <label for="agent_last_name">Last Name</label>
              <input type="text" id="agent_last_name" name="last_name" required>
            </div>
          </div>
          <div class="form-group">
            <label for="agent_username">Username</label>
            <input type="text" id="agent_username" name="username" required>
          </div>
        </div>

        <div class="form-section">
          <div class="form-section-title">Contact Information</div>
          <div class="form-row">
            <div class="form-group">
              <label for="agent_email">Email</label>
              <input type="email" id="agent_email" name="email" required>
            </div>
            <div class="form-group">
              <label for="agent_phone">Phone</label>
              <input type="tel" id="agent_phone" name="phone" required>
            </div>
          </div>
          <div class="form-group">
            <label for="agent_address">Address</label>
            <textarea id="agent_address" name="address" required></textarea>
          </div>
        </div>

        <div class="form-section">
          <div class="form-section-title">Profile Photo (Optional)</div>
          <div class="photo-upload-section">
            <div class="photo-placeholder" id="agent-photo-preview">
              No Photo
            </div>
            <div class="file-input-wrapper">
              <input type="file" id="agent_photo" name="photo" accept="image/*"
                    onchange="previewPhoto(this, 'agent-photo-preview')">
              <label for="agent_photo" class="file-input-label">Choose Photo</label>
            </div>
            <div style="font-size: 12px; color: #999; margin-top: 8px;">
              JPG, PNG, or GIF (Max 5MB) — Optional
            </div>
          </div>
        </div>

    <div class="form-section">
    <div class="form-section-title">Account Security</div>

    <div class="form-row">
      <div class="form-group">
        <label for="agent_password">Password</label>
        <input type="password" id="agent_password" name="password" required>
      </div>

      <div class="form-group">
        <label for="agent_confirm_password">Confirm Password</label>
        <input type="password" id="agent_confirm_password" name="confirm_password" required>
        <small id="agent-password-error" style="color:#dc3545;display:none;">
          Passwords do not match.
        </small>
      </div>
    </div>
  </div> <div class="form-section">
    <div class="form-section-title">Availability Status</div>
    <div class="availability-toggle">
      <label class="toggle-switch">
        <input type="checkbox" name="availability" id="agent_availability" checked>
        <span class="slider"></span>
      </label>
      <span>Available for client assignments</span>
    </div>
  </div>


        <button type="submit" class="btn-primary">Create Agent Account</button>
        <button type="button" class="btn btn-danger" onclick="resetForm('agent-account-form')">Cancel</button>
      </form>
    </div>


    <div class="accounts-table">
      <h3>Existing Agent Accounts</h3>

      <?php if (empty($agentAccounts)): ?>
        <div class="empty-state" style="
          background:#fafafa;
          padding:40px;
          text-align:center;
          border-radius:8px;
          font-size:18px;
          color:#666;
        ">
          <p>No agent accounts found in the database.</p>
        </div>
      <?php else: ?>
        <table style="width:100%; border-collapse:collapse;">
          <thead>
            <tr style="background:#14532d; color:#fff;">
              <th style="padding:12px 10px;">Name</th>
              <th style="padding:12px 10px;">Username</th>
              <th style="padding:12px 10px;">Email</th>
              <th style="padding:12px 10px;">Mobile</th>
              <th style="padding:12px 10px;">Address</th>
              <th style="padding:12px 10px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($agentAccounts as $agent): ?>
              <tr style="border-bottom:1px solid #eee; background:#fff;">
                <td style="padding:10px 8px;"><strong><?php echo htmlspecialchars(trim(($agent['first_name'] ?? '') . ' ' . ($agent['middle_name'] ?? '') . ' ' . ($agent['last_name'] ?? '')) ?: 'N/A'); ?></strong></td>
                <td style="padding:10px 8px; min-width:120px; word-break:break-all;"><?php echo htmlspecialchars($agent['username'] ?? ''); ?></td>
                <td style="padding:10px 8px;"><?php echo htmlspecialchars($agent['email'] ?? ''); ?></td>
                <td style="padding:10px 8px;"><?php echo htmlspecialchars($agent['phone'] ?? ''); ?></td>
                <td style="padding:10px 8px;"><?php echo htmlspecialchars($agent['address'] ?? ''); ?></td>
                <td style="padding:10px 8px;">
                  <button onclick="viewProfile(<?php echo (int)$agent['id']; ?>, 'agent')" class="btn-small" style="padding:10px 16px; font-size:13px; margin-right:3px;">View</button>
                  <button onclick="editAccount(<?php echo (int)$agent['id']; ?>, 'agent')" class="btn-small lot-action-btn-edit" style="padding:10px 16px; font-size:13px; margin-right:3px; background:#2196f3; color:#fff; border:1px solid #2196f3;">Edit</button>
                  <form method="POST" style="display:inline;" onsubmit="return confirmFormSubmit(event, this, 'Are you sure you want to delete this agent account?');">
                    <input type="hidden" name="agent_action" value="delete">
                    <input type="hidden" name="agent_id" value="<?php echo (int)$agent['id']; ?>">
                    <button type="submit" class="btn-small btn-danger" style="padding:10px 16px; font-size:13px; background:#dc3545; color:#fff; border:1px solid #dc3545;">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>


      <div id="user-accounts" class="account-section">
        <div class="form-container">
          <div class="form-title">Create User Account</div>
          
          <form method="POST" id="user-account-form">
            <input type="hidden" name="user_action" value="add">
            
            <div class="form-section">
              <div class="form-section-title">PERSONAL INFORMATION</div>
              <div class="form-row-three">
                <div class="form-group">
                  <label for="user_first_name">First Name</label>
                  <input type="text" id="user_first_name" name="first_name" required>
                </div>
                <div class="form-group">
                  <label for="user_middle_name">Middle Name (Optional)</label>
                  <input type="text" id="user_middle_name" name="middle_name">
                </div>
                <div class="form-group">
                  <label for="user_last_name">Last Name</label>
                  <input type="text" id="user_last_name" name="last_name" required>
                </div>
              </div>
              <div class="form-group">
                <label for="user_username">Username</label>
                <input type="text" id="user_username" name="username" required>
              </div>
            </div>

            <div class="form-section">
              <div class="form-section-title">Contact Information</div>
              <div class="form-row">
                <div class="form-group">
                  <label for="user_email">Email</label>
                  <input type="email" id="user_email" name="email" required>
                </div>
                <div class="form-group">
                  <label for="user_mobile">Phone</label>
                  <input type="tel" id="user_mobile" name="phone_number" required>
                </div>
              </div>
              <div class="form-group">
                <label for="user_address">Address</label>
                <textarea id="user_address" name="address" required></textarea>
              </div>
            </div>

          <div class="form-section">
    <div class="form-section-title">Account Security</div>

    <div class="form-row">
      <div class="form-group">
        <label for="user_password">Password</label>
        <input type="password" id="user_password" name="password" required>
      </div>

      <div class="form-group">
        <label for="user_confirm_password">Confirm Password</label>
        <input type="password" id="user_confirm_password" required>
        <small id="user-password-error"
              style="color:#dc3545;display:none;font-size:13px;">
          Passwords do not match.
        </small>
      </div>
    </div>
  </div>

  <button type="submit" class="btn-primary">Create User Account</button>
  <button type="button" class="btn btn-danger" onclick="resetForm('user-account-form')">
    Cancel
  </button>
          </form>
        </div>
        <div class="accounts-table">
          <h3>Existing User Accounts</h3>
          <div style="margin-bottom:10px;">
            <input type="text" id="userAccountsSearch" placeholder="Search user by name, email, mobile, address" style="width:100%; max-width:420px; padding:8px 10px; border:1px solid #ddd; border-radius:6px;">
          </div>
          <?php if (empty($userAccounts)): ?>
            <div class="empty-state">
              <p>No user accounts found in the database.</p>
            </div>
          <?php else: ?>
            <table>
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Email</th>
                  <th>Mobile</th>
                  <th>Address</th>
                  <th>Created</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($userAccounts as $user): ?>
                <tr class="user-account-row">
                  <td>
                    <strong><?php echo htmlspecialchars($user['first_name'] . ' ' . ($user['middle_name'] ? $user['middle_name'] . ' ' : '') . $user['last_name']); ?></strong>
                  </td>
                  <td><?php echo htmlspecialchars($user['email']); ?></td>
                  <td><?php echo htmlspecialchars($user['mobile_number']); ?></td>
                  <td><?php echo htmlspecialchars(substr($user['address'], 0, 50)); ?>...</td>
                  <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                  <td>
                    <button onclick="viewProfile(<?php echo $user['id']; ?>, 'user')" class="btn-small" style="padding:10px 16px; font-size:13px; margin-right:3px;">View</button>
                    <button onclick="editAccount(<?php echo $user['id']; ?>, 'user')" class="btn-small lot-action-btn-edit" style="padding:10px 16px; font-size:13px; margin-right:3px; background:#2196f3; color:#fff; border:1px solid #2196f3;">Edit</button>
                    <form method="POST" style="display: inline;" onsubmit="return confirmFormSubmit(event, this, 'Are you sure you want to delete this user account?')">
                      <input type="hidden" name="user_action" value="delete">
                      <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                      <button type="submit" class="btn-small btn-danger" style="padding:10px 16px; font-size:13px; background:#dc3545; color:#fff; border:1px solid #dc3545;">Delete</button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>

      <div id="section-lots" class="section hidden">
        <div class="header">
          <div>
            <h2>Manage Lots</h2>
            <small>Create, edit, and manage property lots</small>
          </div>
        </div>

        <div class="table-section">
              <div class="location-dropdown">
            <label for="location_id" style="font-weight:500;">Location:</label>
            <select id="location_id" name="location_id">
      <option value="" disabled selected>Please select a location first</option>
            </select>
            <button type="button" class="add-location-btn" onclick="openAddLocationModal()" style="background-color: #3e5f3e; color: white; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; white-space: nowrap;">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 5v14M5 12h14"/>
              </svg>
              Add Location
            </button>
            <button type="button" class="delete-location-btn" onclick="deleteSelectedLocation()" style="background:#dc3545; color: white; padding: 10px 20px; border: 1px solid #dc3545; border-radius: 6px; cursor: pointer; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; white-space: nowrap;">Delete Location</button>
          </div>

          <div id="lot-message" style="margin-bottom:15px;display:none;padding:10px 18px;border-radius:6px;font-size:15px;"></div>

          <table id="lots-table">
            <thead>
              <tr>
                <th></th>
                <th>Block Number</th>
                <th>Lot Number</th>
                <th>Lot Size (Sqm)</th>
                <th>Lot Price</th>
                <th>Commission</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>

          <tbody id="lots-table-body">
    <tr id="new-row" class="hidden" style="display:none;">
      
      <td></td>

      <td><input type="text" id="block_number"></td>
      <td><input type="text" id="lot_number"></td>
      <td><input type="text" id="lot_size"></td>
      <td><input type="text" id="lot_price"></td>
      <td><input type="text" id="commission_amount" placeholder="0.00"></td>

      <td>
        <select id="status">
          <option value="Available">Available</option>
        </select>
      </td>

      <td>
        <div class="new-lot-actions">
          <button onclick="saveLot()">Save</button>
          <button onclick="cancelAdd()">Cancel</button>
        </div>
      </td>
    </tr>
  </tbody>
          </table>


          <button onclick="addNewLot()">Add New Lot</button>
          <button onclick="bulkDeleteLots()" class="btn btn-danger" style="margin-top:10px; background:#dc3545; color:#fff; border:1px solid #dc3545;">Delete Selected Lots</button>
        </div>
      </div>

      <div id="editLotModal" style="
        display: none;
        position: fixed;
        z-index: 2100;
        left: 0; top: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.6);
        justify-content: center; align-items: center;
        overflow: auto;
      ">
        <div style="
          background: #fff;
          padding: 24px;
          border-radius: 8px;
          box-shadow: 0 5px 15px rgba(0,0,0,0.3);
          width: 95%; max-width: 400px; position: relative;
          max-height: 90vh;
          overflow-y: auto;
        ">
          <span onclick="closeEditLotModal()" style="
            color: #aaa; float: right; font-size: 32px; font-weight: normal; line-height: 1; cursor: pointer; margin-left: 15px;
          ">&times;</span>
          <h3 style="color: #3e5f3e; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
            Edit Lot
          </h3>
          <form id="editLotForm">
            <input type="hidden" name="lot_id" id="edit_lot_id">
            <div id="editLotFields"></div>
            <button type="submit" class="btn-primary" style="margin-top: 18px;">Save Changes</button>
          </form>
        </div>
      </div>

      <!-- Blueprint Pin Modal -->
      <div id="pinModal" class="pin-modal-overlay">
        <div class="pin-modal-card">
          <!-- Header -->
          <div class="pin-modal-header">
            <h3 class="pin-modal-title">
              Mapping: <span id="pinModalLotInfo"></span>
            </h3>
            <span onclick="closePinModal()" class="pin-modal-close">&times;</span>
          </div>

          <!-- Content -->
          <div class="pin-modal-content">
            <!-- Status Selection Buttons -->
            <div class="pin-status-row">
              <button id="statusBtn_Available" type="button" onclick="selectLotStatus('Available')" class="pin-status-btn pin-status-btn-available" style="background:#28a745;color:#fff;">
                Available
              </button>
              <button id="statusBtn_Reserved" type="button" onclick="selectLotStatus('Reserved')" class="pin-status-btn pin-status-btn-reserved">
                Reserved
              </button>
              <button id="statusBtn_Sold" type="button" onclick="selectLotStatus('Sold')" class="pin-status-btn pin-status-btn-sold">
                Sold
              </button>
            </div>

            <div class="pin-modal-help">
              Click each corner/edge point of the lot to trace its real shape. Click near the first point (or double-click) to close the polygon.
            </div>

            <!-- Blueprint Container -->
            <div class="blueprint-stage" id="blueprint-stage">
              <div class="blueprint-wrapper" id="blueprint-wrapper" style="position: relative; display: inline-block;">
                <img id="blueprintImage" src="" alt="Blueprint" style="display: block; max-width: 100%; height: auto; pointer-events: none;">
                <div id="draw-layer" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 10;"></div>
              </div>
            </div>

          <!-- Footer with Buttons -->
          <div class="pin-modal-footer">
            <button onclick="toggleDrawingMode()" id="toggleDrawBtn" class="pin-modal-footer-btn pin-modal-footer-btn-draw">Start Drawing Polygon</button>
            <button onclick="closePinModal()" class="pin-modal-footer-btn pin-modal-footer-btn-cancel">Cancel</button>
            <button onclick="savePinLocation()" class="pin-modal-footer-btn pin-modal-footer-btn-save">Save Pin Location</button>
          </div>
          </div><!-- end pin-modal-content -->
        </div><!-- end pin-modal-card -->
      </div><!-- end pinModal -->

      <!-- Add Location Modal -->
      <div id="addLocationModal" style="
        display: none;
        position: fixed;
        z-index: 2200;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.6);
        justify-content: center;
        align-items: center;
        overflow: auto;
      " class="location-modal">
        <div style="
          background-color: white;
          padding: 25px;
          border-radius: 10px;
          box-shadow: 0 5px 20px rgba(0, 0, 0, 0.3);
          max-width: 800px;
          width: 90%;
          max-height: 90vh;
          overflow-y: auto;
        ">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 style="margin: 0; color: #2d482d; font-size: 24px;">Add New Location</h3>
            <button onclick="closeAddLocationModal()" style="background: none; border: none; font-size: 32px; color: #aaa; cursor: pointer; padding: 0; width: 32px; height: 32px; line-height: 1; min-width: unset;">&times;</button>
          </div>

          <div style="background-color: #e8f5e9; color: #2e7d32; padding: 12px 15px; border-radius: 5px; margin-bottom: 15px; font-size: 14px; display: flex; align-items: center; gap: 8px;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
              <circle cx="12" cy="10" r="3"></circle>
            </svg>
            Click anywhere on the map to pin the location
          </div>

          <div id="location-map" style="height: 400px; width: 100%; margin: 20px 0; border-radius: 8px; border: 1px solid #ddd;"></div>

          <form id="locationForm" onsubmit="saveNewLocation(event)">
            <div style="margin-bottom: 15px;">
              <label for="new_location_name" style="display: block; margin-bottom: 6px; color: #333; font-weight: 500; font-size: 14px;">Location Name:</label>
              <input type="text" id="new_location_name" name="location_name" required style="width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px;">
            </div>
            <div style="margin-bottom: 15px;">
              <label style="display: block; margin-bottom: 6px; color: #333; font-weight: 500; font-size: 14px;">Latitude:</label>
              <input type="text" id="new_latitude_display" readonly style="width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; background-color: #f5f5f5; color: #666;">
              <input type="hidden" id="new_latitude" name="latitude">
            </div>
            <div style="margin-bottom: 15px;">
              <label style="display: block; margin-bottom: 6px; color: #333; font-weight: 500; font-size: 14px;">Longitude:</label>
              <input type="text" id="new_longitude_display" readonly style="width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; background-color: #f5f5f5; color: #666;">
              <input type="hidden" id="new_longitude" name="longitude">
            </div>
            <div style="margin-bottom: 15px;">
              <label style="display: block; margin-bottom: 6px; color: #333; font-weight: 500; font-size: 14px;">Blueprint Image (Optional):</label>
              <div id="blueprint-upload-area" style="border: 2px dashed #b0c4a8; border-radius: 8px; padding: 20px; text-align: center; cursor: pointer; transition: border-color 0.2s, background 0.2s;" onclick="document.getElementById('new_blueprint_file').click()">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#6b8f5e" stroke-width="1.5" style="margin-bottom: 8px;"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                <div id="blueprint-upload-text" style="color: #666; font-size: 13px;">Click to upload a blueprint image<br><span style="font-size: 12px; color: #999;">JPG, PNG, or GIF</span></div>
                <input type="file" id="new_blueprint_file" name="blueprint" accept="image/jpeg,image/png,image/gif" style="display:none;" onchange="previewBlueprintFile(this)">
              </div>
              <div id="blueprint-preview-container" style="display:none; margin-top: 10px; position: relative;">
                <img id="blueprint-preview-img" style="max-width: 100%; max-height: 200px; border-radius: 6px; border: 1px solid #ddd;" />
                <button type="button" onclick="removeBlueprintPreview()" style="position: absolute; top: 4px; right: 4px; width: 24px; height: 24px; border-radius: 50%; background: rgba(0,0,0,0.6); color: #fff; border: none; cursor: pointer; font-size: 14px; line-height: 1; display: flex; align-items: center; justify-content: center;">&times;</button>
              </div>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
              <button type="button" onclick="closeAddLocationModal()" style="background-color: #999; color: white; padding: 10px 24px; border: none; border-radius: 5px; cursor: pointer; font-size: 14px;">Cancel</button>
              <button type="submit" style="background-color: #3e5f3e; color: white; padding: 10px 24px; border: none; border-radius: 5px; cursor: pointer; font-size: 14px;">Save Location</button>
            </div>
          </form>
        </div>
      </div>
      
      <div id="section-viewings" class="section hidden">
        <div class="header">
          <div>
            <h2>Manage Viewing Requests</h2>
            <small>Review and assign viewing requests to agents</small>
          </div>
        </div>

        <div class="table-section">
          <?php if (isset($success_message)): ?>
            <div class="alert success"><?php echo $success_message; ?></div>
          <?php endif; ?>

          <?php if (isset($error_message)): ?>
            <div class="alert error"><?php echo $error_message; ?></div>
          <?php endif; ?>

          <?php if (empty($all_viewings)): ?>
            <div style="text-align: center; padding: 40px; color: #666;">
              <div style="font-size: 48px; margin-bottom: 20px;">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="color: #999;">
                  <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                  <path d="M12 9V13M12 17H12.01M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </div>
              <p style="font-size: 18px; margin-bottom: 10px;">No viewing requests yet</p>
              <p>When users submit viewing requests, they will appear here.</p>
            </div>
          <?php else: ?>
            <table>
              <thead>
                <tr>
                  <th>Client</th>
                  <th>Contact</th>
                  <th>Location</th>
                  <th>Lot Details</th>
                  <th>Preferred Date</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($all_viewings as $viewing): ?>
                  <tr style="border-bottom:1px solid #eee; background:#fff;">
                    <td>
                      <strong><?php echo htmlspecialchars($viewing['client_first_name'] . ' ' . $viewing['client_last_name']); ?></strong>
                      <?php if (!empty($viewing['note'])): ?>
                          <br><small style="color: #666;">Note: <?php echo htmlspecialchars($viewing['note']); ?></small>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div class="viewing-client-meta">
                        <a href="mailto:<?php echo htmlspecialchars($viewing['client_email'] ?? 'N/A'); ?>" style="color: #2d482d; text-decoration: none;">
                          <?php echo htmlspecialchars($viewing['client_email'] ?? 'N/A'); ?>
                        </a>
                      </div>
                      <div class="viewing-client-meta">
                        <a href="tel:<?php echo htmlspecialchars($viewing['client_phone'] ?? 'N/A'); ?>" style="color: #2d482d; text-decoration: none;">
                          <?php echo htmlspecialchars($viewing['client_phone'] ?? 'N/A'); ?>
                        </a>
                      </div>
                    </td>
                    <td><?php echo htmlspecialchars($viewing['location_name']); ?></td>
                    <td>
                      <div class="viewing-lot-details">
                        <div class="viewing-lot-row">
                          <span class="viewing-lot-label">Block</span>
                          <span class="viewing-lot-value"><?php echo htmlspecialchars($viewing['block_number']); ?></span>
                        </div>
                        <div class="viewing-lot-row">
                          <span class="viewing-lot-label">Lot</span>
                          <span class="viewing-lot-value"><?php echo htmlspecialchars($viewing['lot_number']); ?></span>
                        </div>
                        <div class="viewing-lot-row">
                          <span class="viewing-lot-label">Size</span>
                          <span class="viewing-lot-value"><?php echo htmlspecialchars($viewing['lot_size']); ?> sqm</span>
                        </div>
                        <div class="viewing-lot-row">
                          <span class="viewing-lot-label">Price</span>
                          <span class="viewing-lot-value">₱<?php echo number_format($viewing['lot_price'], 2); ?></span>
                        </div>
                      </div>
                    </td>
                    <td><span class="viewing-date-pill"><?php echo date('M d, Y', strtotime($viewing['preferred_at'])); ?></span></td>
                    <td>
                      <span class="status-badge status-<?php echo strtolower($viewing['status']); ?>">
                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $viewing['status']))); ?>
                      </span>
                    </td>
                      <td>
                        <div class="viewing-actions">
                          <?php
                            $assignedAgent = null;
                            if (!empty($viewing['agent_id'])) {
                              foreach ($agents as $agent) {
                                if ($agent['id'] == $viewing['agent_id']) {
                                  $assignedAgent = $agent;
                                  break;
                                }
                              }
                            }
                          ?>
                          <?php if ($assignedAgent): ?>
                            <div class="viewing-assigned-card">
                              <span class="viewing-assigned-label">Assigned Agent</span>
                              <span class="viewing-assigned-name"><?php echo htmlspecialchars($assignedAgent['first_name'] . ' ' . $assignedAgent['last_name']); ?></span>
                              <button type="button"
                                class="btn-small"
                                style="padding:10px 16px; font-size:13px; margin-top:4px;"
                                onclick='viewProfile(<?= $viewing['user_id'] ?: 0 ?>, "user", event, <?= json_encode((string)($viewing['client_email'] ?? '')) ?>, <?= json_encode((string)($viewing['client_phone'] ?? '')) ?>)'>
                                View Client
                              </button>
                            </div>
                          <?php else: ?>
                            <form method="POST" class="viewing-assignment-form">
                              <input type="hidden" name="viewing_action" value="assign_agent">
                              <input type="hidden" name="viewing_id" value="<?php echo $viewing['id']; ?>">
                              <select name="agent_id" required>
                                <option value="">Select Agent</option>
                                <?php foreach ($agents as $agent): ?>
                                  <option value="<?php echo $agent['id']; ?>"><?php echo htmlspecialchars($agent['first_name'] . ' ' . $agent['last_name']); ?></option>
                                <?php endforeach; ?>
                              </select>
                              <div class="viewing-assignment-buttons">
                                <button type="submit" class="btn-small" style="padding:10px 16px; font-size:13px;">Assign</button>
                                <button type="button"
                                  class="btn-small"
                                  style="padding:10px 16px; font-size:13px;"
                                  onclick='viewProfile(<?= $viewing['user_id'] ?: 0 ?>, "user", event, <?= json_encode((string)($viewing['client_email'] ?? '')) ?>, <?= json_encode((string)($viewing['client_phone'] ?? '')) ?>)'>
                                  View Client
                                </button>
                              </div>
                            <?php endif; ?>
                          </form>
                        </div>
                      </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>

      <div id="viewClientModal" style="
        display: none; 
        position: fixed; 
        z-index: 2000;
        left: 0; 
        top: 0; 
        width: 100%; 
        height: 100%; 
        overflow: auto; 
        background-color: rgba(0,0,0,0.6);
        justify-content: center; 
        align-items: center;
      ">
        <div style="
          background-color: #fff;
          padding: 20px;
          border-radius: 8px;
          box-shadow: 0 5px 15px rgba(0,0,0,0.3);
          width: 90%; 
          max-width: 450px; 
          position: relative;
        ">
          <span onclick="closeViewClientModal()" style="
            color: #aaa;
            float: right;
            font-size: 32px;
            font-weight: normal;
            line-height: 1;
            cursor: pointer;
            margin-left: 15px;
          ">&times;</span>
          
          <h3 style="color: #3e5f3e; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
            Client Profile
          </h3>
          
          <div id="viewClientContent">
            Loading client details...
          </div>
        </div>
      </div>

      <div id="editAccountModal" style="
        display: none;
        position: fixed;
        z-index: 2100;
        left: 0; top: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.6);
        justify-content: center; align-items: center;
        overflow: auto;
      ">
        <div style="
          background: #fff;
          padding: 24px;
          border-radius: 8px;
          box-shadow: 0 5px 15px rgba(0,0,0,0.3);
          width: 95%; max-width: 500px; position: relative;
          max-height: 90vh;
          overflow-y: auto;
        ">
          <span onclick="closeEditAccountModal()" style="
            color: #aaa; float: right; font-size: 32px; font-weight: normal; line-height: 1; cursor: pointer; margin-left: 15px;
          ">&times;</span>
          <h3 style="color: #3e5f3e; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
            Edit Account
          </h3>
          <form id="editAccountForm" enctype="multipart/form-data">
            <input type="hidden" id="edit_account_id" name="account_id">
            <input type="hidden" id="edit_account_type" name="account_type">
            <div id="editAccountPhotoSection"></div>
            <div id="editAccountFields"></div>
            <button type="submit" class="btn-primary" style="margin-top: 18px;">Save Changes</button>
          </form>
        </div>
      </div>

      <div id="section-analytics" class="section hidden">
        <div class="header">
          <div>
            <h2>Analytics Dashboard</h2>
            <small>Track sales performance and agent statistics</small>
          </div>
        </div>

        <div class="table-section" style="margin-bottom: 24px; padding: 18px;">
          <div style="display:grid; grid-template-columns: 1fr 1fr 1fr 1fr auto auto; gap:10px; margin-bottom: 14px; align-items:end;">
            <input type="date" id="analytics_date_from" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="Date From">
            <input type="date" id="analytics_date_to" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="Date To">
            <select id="analytics_location" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;">
              <option value="">All Locations</option>
            </select>
            <select id="analytics_sales_period" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;">
              <option value="daily">Daily</option>
              <option value="weekly">Weekly</option>
              <option value="monthly" selected>Monthly</option>
              <option value="yearly">Yearly</option>
            </select>
            <button type="button" class="btn-primary" onclick="loadAnalyticsData()">Generate</button>
            <button type="button" class="btn" onclick="exportAnalytics()">Download Report</button>
          </div>

          <div style="display:flex; flex-wrap:wrap; gap:12px; align-items:center; justify-content:space-between; padding:12px 14px; background:#f8faf8; border:1px solid #e4ebe5; border-radius:8px; margin-bottom: 14px;">
            <div>
              <div style="font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:0.04em;">Analytics View</div>
              <div id="analytics-view-range-label" style="font-size:13px; color:#2f3b33; margin-top:4px;">Monthly sales view</div>
            </div>
            <div style="font-size:12px; color:#6b7280;">Use the filters above to refresh the analytics cards, chart, and export.</div>
          </div>
        </div>

        <div class="analytics-kpis" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
          <div class="kpi-card" style="background: white; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-top: 30px;">
            <div style="display: flex; align-items: center; justify-content: space-between;">
              <div>
                <div style="font-size: 12px; font-weight: 600; color: #2d482d; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Total Sales</div>
                <div id="kpi-total-sales" style="font-size: 28px; font-weight: bold; color: #2d482d;">Loading...</div>
                <div id="kpi-total-sales-range-label" style="font-size: 12px; color: #666; margin-top: 4px;">Based on selected filters</div>
              </div>
              <div style="width: 50px; height: 50px; background: #2d482d; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                <svg width="24" height="24" fill="white" viewBox="0 0 24 24">
                  <path d="M12 9V13M12 17H12.01M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12z"/>
                </svg>
              </div>
            </div>
          </div>

          <div class="kpi-card" style="background: white; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-top: 30px;">
            <div style="display: flex; align-items: center; justify-content: space-between;">
              <div>
                <div style="font-size: 12px; font-weight: 600; color: #2d482d; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Closed Sales</div>
                <div id="kpi-closed-sales" style="font-size: 28px; font-weight: bold; color: #2d482d;">Loading...</div>
                <div style="font-size: 12px; color: #666; margin-top: 4px;">Fully paid lots only</div>
              </div>
              <div style="width: 50px; height: 50px; background: #16a34a; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                <svg width="24" height="24" fill="white" viewBox="0 0 24 24">
                  <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                </svg>
              </div>
            </div>
          </div>

          <div class="kpi-card" style="background: white; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-top: 30px;">
            <div style="display: flex; align-items: center; justify-content: space-between;">
              <div>
                <div style="font-size: 12px; font-weight: 600; color: #2d482d; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Ongoing Sales</div>
                <div id="kpi-ongoing-sales" style="font-size: 28px; font-weight: bold; color: #2d482d;">Loading...</div>
                <div style="font-size: 12px; color: #666; margin-top: 4px;">Installment/down payment</div>
              </div>
              <div style="width: 50px; height: 50px; background: #d97706; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                <svg width="24" height="24" fill="white" viewBox="0 0 24 24">
                  <path d="M13 2L3 14h7v8l10-12h-7z"/>
                </svg>
              </div>
            </div>
          </div>

          <div class="kpi-card" style="background: white; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-top: 30px;">
            <div style="display: flex; align-items: center; justify-content: space-between;">
              <div>
                <div style="font-size: 12px; font-weight: 600; color: #2d482d; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Total Lots</div>
                <div id="kpi-total-lots" style="font-size: 28px; font-weight: bold; color: #2d482d;">Loading...</div>
                <div style="font-size: 12px; color: #666; margin-top: 4px;">Available properties</div>
              </div>
              <div style="width: 50px; height: 50px; background: #17a2b8; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                <svg width="24" height="24" fill="white" viewBox="0 0 24 24">
                  <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                </svg>
              </div>
            </div>
          </div>

          <div class="kpi-card" style="background: white; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);margin-top: 30px;">
            <div style="display: flex; align-items: center; justify-content: space-between;">
              <div>
                <div style="font-size: 12px; font-weight: 600; color: #2d482d; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Available Agents</div>
                <div id="kpi-available-agents" style="font-size: 28px; font-weight: bold; color: #2d482d;">Loading...</div>
                <div style="font-size: 12px; color: #666; margin-top: 4px;">Active and ready</div>
              </div>
              <div style="width: 50px; height: 50px; background: #28a745; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                <svg width="24" height="24" fill="white" viewBox="0 0 24 24">
                  <path d="M16 4c0-1.11.89-2 2-2s2 .89 2 2-.89 2-2 2-2-.89-2-2zm4 18v-6h2.5l-2.54-7.63A2 2 0 0 0 17.87 7H14.8c-.8 0-1.54.35-2.05.96L11 10.5V7H9v8h2.5l1.5-3.5 2 6z"/>
                </svg>
              </div>
            </div>
          </div>

          <div class="kpi-card" style="background: white; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-top: 30px;">
            <div style="display: flex; align-items: center; justify-content: space-between;">
              <div>
                <div style="font-size: 12px; font-weight: 600; color: #2d482d; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Pending Documents</div>
                <div id="kpi-pending-documents" style="font-size: 28px; font-weight: bold; color: #2d482d;">Loading...</div>
                <div style="font-size: 12px; color: #666; margin-top: 4px;">Awaiting review</div>
              </div>
              <div style="width: 50px; height: 50px; background: #ffc107; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                <svg width="24" height="24" fill="white" viewBox="0 0 24 24">
                  <path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 2 2h8c1.1 0 2-.9 2-2V8l-6-6zm4 18H6V4h7v5h5v11z"/>
                </svg>
              </div>
            </div>
          </div>
        </div>

        <div style="background: white; border: 1px solid #e0e0e0; border-radius: 8px; margin-bottom: 30px; overflow: hidden;">
          <div style="background: #f8f9fa; padding: 20px; border-bottom: 1px solid #e0e0e0;">
            <div style="display: flex; flex-wrap: wrap; align-items: end; justify-content: space-between; gap: 12px;">
              <div>
                <h3 id="top-agents-title" style="margin: 0; color: #2d482d; font-size: 18px; font-weight: 600;">Top Agents by Sales</h3>
                <div id="top-agents-subtitle" style="font-size: 12px; color: #666; margin-top: 5px;">Sales scope: All qualified sales</div>
              </div>
              <div style="display: flex; flex-wrap: wrap; align-items: end; gap: 8px;">
                <div>
                  <label for="top_agents_rank_mode" style="display: block; font-size: 11px; color: #666; margin-bottom: 4px;">Ranking</label>
                  <select id="top_agents_rank_mode" style="padding: 8px 10px; border: 1px solid #ddd; border-radius: 4px; min-width: 220px;">
                    <option value="sales">Top Sales Ranking</option>
                    <option value="encouragement">Top Encouragement (Not Fully Paid)</option>
                  </select>
                </div>
                <div>
                  <label for="top_agents_sales_scope" style="display: block; font-size: 11px; color: #666; margin-bottom: 4px;">Sales Scope</label>
                  <select id="top_agents_sales_scope" style="padding: 8px 10px; border: 1px solid #ddd; border-radius: 4px; min-width: 220px;">
                    <option value="all">All qualified sales</option>
                    <option value="fully_paid_only">Fully paid only</option>
                    <option value="not_fully_paid_only">Not fully paid only</option>
                  </select>
                </div>
              </div>
            </div>
          </div>
          <div id="top-agents-loading" style="text-align: center; padding: 40px; color: #666;">
            Loading agents data...
          </div>
          <div id="top-agents-content" style="display: none;">
            <table id="top-agents-table" style="width: 100%; border-collapse: collapse;">
              <thead>
                <tr style="background: #f8f9fa;">
                  <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0;">Rank</th>
                  <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0;">Agent</th>
                  <th id="top-agents-primary-metric-label" style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0;">Sales Count</th>
                  <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0;">Sold Lots</th>
                  <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0;">Reserved Lots</th>
                  <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0;">Ongoing Lots</th>
                  <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0;">Cancelled Lots</th>
                  <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0;">Total Amount</th>
                  <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0;">Avg Deal Size</th>
                </tr>
              </thead>
              <tbody id="top-agents-tbody">
                </tbody>
            </table>
          </div>
        </div>

        <div style="background: white; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
          <div style="background: #f8f9fa; padding: 20px; border-bottom: 1px solid #e0e0e0;">
            <h3 id="monthly-sales-title" style="margin: 0; color: #2d482d; font-size: 18px; font-weight: 600;">Sales Trend</h3>
          </div>
          <div id="monthly-sales-chart-wrap" style="position: relative; padding: 24px 24px 18px; background: linear-gradient(180deg, #fcfdfc 0%, #f6f9f6 100%);">
            <canvas id="monthly-sales-chart" style="display:block; width:100%; height:320px;"></canvas>
            <div id="monthly-sales-tooltip" style="display:none; position:absolute; z-index:5; pointer-events:none; background:rgba(18,24,22,0.94); color:#fff; padding:8px 10px; border-radius:8px; font-size:12px; line-height:1.35; white-space:nowrap; box-shadow:0 8px 18px rgba(0,0,0,0.22);"></div>
            <div style="margin-top: 10px; font-size: 12px; color: #6b7280;">Values are shown in PHP currency (PHP).</div>
          </div>
        </div>
      </div>

      <div id="section-reports" class="section hidden">
        <div class="header">
          <div>
            <h2>Printable Reports</h2>
            <small>Generate formatted records for printing or Save as PDF</small>
          </div>
        </div>

        <div class="table-section">
          <div style="display:grid; grid-template-columns: 1fr 1fr 1fr 1fr 1fr auto auto; gap:10px; margin-bottom: 14px; align-items:end;">
            <input type="date" id="report_date_from" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="Date From">
            <input type="date" id="report_date_to" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="Date To">
            <select id="report_sales_period" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;">
              <option value="daily">Today</option>
              <option value="weekly">This Week</option>
              <option value="monthly" selected>This Month</option>
              <option value="yearly">This Year</option>
              <option value="custom">Custom Date Range</option>
            </select>
            <select id="report_location" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;">
              <option value="">All Locations</option>
            </select>
            <select id="report_agent" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;">
              <option value="">All Agents</option>
            </select>
            <button type="button" class="btn-primary" onclick="loadPrintableReports()">Generate</button>
            <button type="button" class="btn" onclick="downloadReportsFile()">Download Report</button>
          </div>

          <div id="reports-print-area" style="background:#fff; border:1px solid #e0e0e0; border-radius:8px; padding:16px;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:14px; flex-wrap:wrap; margin-bottom:14px;">
              <div>
                <h3 style="margin:0; color:#2d482d;">Nuevo Puerta - Admin Reports</h3>
                <div id="reports-meta" style="font-size:12px; color:#666; margin-top:6px;">No report generated yet.</div>
              </div>
              <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <div style="background:#f8f9fa; border:1px solid #e6e6e6; border-radius:8px; padding:10px 12px; min-width:170px;">
                  <div style="font-size:11px; color:#666; text-transform:uppercase;">Closed Sales</div>
                  <div id="report-kpi-closed-sales" style="font-size:20px; font-weight:700; color:#2d482d;">0</div>
                </div>
                <div style="background:#f8f9fa; border:1px solid #e6e6e6; border-radius:8px; padding:10px 12px; min-width:170px;">
                  <div style="font-size:11px; color:#666; text-transform:uppercase;">Total Sales Amount</div>
                  <div id="report-kpi-total-sales" style="font-size:20px; font-weight:700; color:#2d482d;">PHP 0.00</div>
                </div>
                <div style="background:#f8f9fa; border:1px solid #e6e6e6; border-radius:8px; padding:10px 12px; min-width:170px;">
                  <div style="font-size:11px; color:#666; text-transform:uppercase;">Fully Paid Lots</div>
                  <div id="report-kpi-fully-paid" style="font-size:20px; font-weight:700; color:#2d482d;">0</div>
                </div>
              </div>
            </div>

            <div style="margin-top:12px;">
              <h4 style="margin:0 0 8px; color:#2d482d;">List of Agents</h4>
              <div style="overflow:auto; max-height:220px; border:1px solid #ececec; border-radius:8px;">
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                  <thead style="background:#f8f9fa;">
                    <tr>
                      <th style="text-align:left; padding:10px 12px; border-bottom:1px solid #ececec;">Agent</th>
                      <th style="text-align:left; padding:10px 12px; border-bottom:1px solid #ececec;">Email</th>
                      <th style="text-align:left; padding:10px 12px; border-bottom:1px solid #ececec;">Mobile</th>
                      <th style="text-align:left; padding:10px 12px; border-bottom:1px solid #ececec;">Status</th>
                    </tr>
                  </thead>
                  <tbody id="report-agents-body">
                    <tr><td colspan="4" style="padding:12px; color:#666;">No data yet.</td></tr>
                  </tbody>
                </table>
              </div>
            </div>

            <div style="margin-top:14px;">
              <h4 style="margin:0 0 8px; color:#2d482d;">Closed Sales</h4>
              <div style="overflow:auto; max-height:240px; border:1px solid #ececec; border-radius:8px;">
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                  <thead style="background:#f8f9fa;">
                    <tr>
                      <th style="text-align:left; padding:10px 12px; border-bottom:1px solid #ececec;">Property</th>
                      <th style="text-align:left; padding:10px 12px; border-bottom:1px solid #ececec;">Agent</th>
                      <th style="text-align:left; padding:10px 12px; border-bottom:1px solid #ececec;">Owner</th>
                      <th style="text-align:left; padding:10px 12px; border-bottom:1px solid #ececec;">Amount</th>
                      <th style="text-align:left; padding:10px 12px; border-bottom:1px solid #ececec;">Closed Date</th>
                    </tr>
                  </thead>
                  <tbody id="report-closed-body">
                    <tr><td colspan="5" style="padding:12px; color:#666;">No data yet.</td></tr>
                  </tbody>
                </table>
              </div>
            </div>

            <div style="margin-top:14px;">
              <h4 style="margin:0 0 8px; color:#2d482d;">Fully Paid Lots</h4>
              <div style="overflow:auto; max-height:220px; border:1px solid #ececec; border-radius:8px;">
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                  <thead style="background:#f8f9fa;">
                    <tr>
                      <th style="text-align:left; padding:10px 12px; border-bottom:1px solid #ececec;">Property</th>
                      <th style="text-align:left; padding:10px 12px; border-bottom:1px solid #ececec;">Location</th>
                      <th style="text-align:left; padding:10px 12px; border-bottom:1px solid #ececec;">Owner</th>
                      <th style="text-align:left; padding:10px 12px; border-bottom:1px solid #ececec;">Total Paid</th>
                      <th style="text-align:left; padding:10px 12px; border-bottom:1px solid #ececec;">Date</th>
                    </tr>
                  </thead>
                  <tbody id="report-fully-paid-body">
                    <tr><td colspan="5" style="padding:12px; color:#666;">No data yet.</td></tr>
                  </tbody>
                </table>
              </div>
            </div>

            <div style="margin-top:14px;">
              <h4 style="margin:0 0 8px; color:#2d482d;">Lots Sold Under Selected Agent</h4>
              <div style="overflow:auto; max-height:220px; border:1px solid #ececec; border-radius:8px;">
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                  <thead style="background:#f8f9fa;">
                    <tr>
                      <th style="text-align:left; padding:10px 12px; border-bottom:1px solid #ececec;">Property</th>
                      <th style="text-align:left; padding:10px 12px; border-bottom:1px solid #ececec;">Agent</th>
                      <th style="text-align:left; padding:10px 12px; border-bottom:1px solid #ececec;">Owner</th>
                      <th style="text-align:left; padding:10px 12px; border-bottom:1px solid #ececec;">Amount</th>
                      <th style="text-align:left; padding:10px 12px; border-bottom:1px solid #ececec;">Date</th>
                    </tr>
                  </thead>
                  <tbody id="report-agent-sold-body">
                    <tr><td colspan="5" style="padding:12px; color:#666;">No data yet.</td></tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div id="section-notifications" class="section hidden">
        <div class="header">
          <div>
            <h2>Notifications</h2>
            <small>System alerts and updates</small>
          </div>
          <?php if ($unreadNotificationsCount > 0): ?>
            <button onclick="markAllNotificationsAsRead()" style="background-color: #1e7c34; color: white; padding: 10px 15px; border: none; border-radius: 4px; font-weight: 500; cursor: pointer; transition: background-color 0.2s;">
              Mark All as Read
            </button>
          <?php endif; ?>
        </div>
        <div class="table-section">
          <div style="display:grid; grid-template-columns: 1fr 1fr 1fr 1fr auto auto; gap:10px; margin-bottom: 12px; align-items:end;">
            <input type="date" id="notif_date_from" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="From">
            <input type="date" id="notif_date_to" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="To">
            <select id="notif_type" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;">
              <option value="">All Types</option>
              <option value="info">Info</option>
              <option value="success">Success</option>
              <option value="warning">Warning</option>
              <option value="error">Error</option>
            </select>
            <input type="text" id="notif_search" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="Search title/message">
            <button type="button" class="btn-primary" onclick="applyNotificationFilters()">Apply</button>
            <button type="button" class="btn" onclick="resetNotificationFilters()">Reset</button>
          </div>
          <div id="notifications-container" class="notifications-container">
            <p style="text-align: center; color: #666;">Loading notifications...</p>
          </div>
        </div>
      </div>

      <div id="section-audit-logs" class="section hidden">
        <div class="header">
          <div>
            <h2>Audit Logs</h2>
            <small>Track admin actions and system changes</small>
          </div>
        </div>
        <div class="table-section">
          <div style="display:grid; grid-template-columns: 1fr 1fr 1fr 1fr auto auto; gap:10px; margin-bottom: 12px; align-items:end;">
            <input type="date" id="audit_date_from" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="From">
            <input type="date" id="audit_date_to" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="To">
            <input type="text" id="audit_action" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="Action">
            <input type="text" id="audit_search" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="Search details">
            <button type="button" class="btn-primary" onclick="applyAuditFilters()">Apply</button>
            <button type="button" class="btn" onclick="resetAuditFilters()">Reset</button>
          </div>
          <div id="audit-logs-container" style="background: #f8f9fa; border-radius: 8px; padding: 20px; max-height: 400px; overflow-y: auto;">
            <p style="text-align: center; color: #666;">Loading audit logs...</p>
          </div>
        </div>
      </div>

      <div id="section-documents" class="section hidden">
        <div class="header">
          <div>
            <h2>Documents</h2>
            <small>Upload soft copies for users and review pending documents</small>
          </div>
        </div>

        <!-- ===== ADMIN UPLOAD FORM ===== -->
        <div class="table-section" style="margin-bottom:22px;">
          <div style="font-size:16px; font-weight:700; color:#2d4e1e; margin-bottom:14px;">Upload New Document</div>
          <div style="display:grid; grid-template-columns:1fr 1fr 1fr 1fr auto; gap:12px; align-items:end;">
            <div>
              <label style="display:block; font-size:13px; color:#495057; margin-bottom:5px;">Client / User</label>
              <select id="admin_upload_user_id" style="width:100%; padding:9px 10px; border:1px solid #ced4da; border-radius:6px; background:#fff;" onchange="onUserChangeForUpload()">
                <option value="">— Loading users… —</option>
              </select>
            </div>
            <div>
              <label style="display:block; font-size:13px; color:#495057; margin-bottom:5px;">Lot (Optional)</label>
              <select id="admin_upload_lot_id" style="width:100%; padding:9px 10px; border:1px solid #ced4da; border-radius:6px; background:#fff;">
                <option value="">Select Lot (leave blank for all lots)</option>
              </select>
            </div>
            <div>
              <label style="display:block; font-size:13px; color:#495057; margin-bottom:5px;">Document Type</label>
              <select id="admin_upload_doc_type" style="width:100%; padding:9px 10px; border:1px solid #ced4da; border-radius:6px; background:#fff;">
                <option value="">Select Document Type</option>
                <option value="Copy of Contract">Copy of Contract</option>
                <option value="Copy of Agreement">Copy of Agreement</option>
              </select>
            </div>
            <div>
              <label style="display:block; font-size:13px; color:#495057; margin-bottom:5px;">Select File (PDF, JPG, PNG)</label>
              <input type="file" id="admin_upload_file" accept=".pdf,.jpg,.jpeg,.png" style="width:100%; padding:7px 8px; border:1px solid #ced4da; border-radius:6px; background:#fff;">
            </div>
            <div>
              <button type="button" class="btn-primary" onclick="adminUploadDocument()" style="padding:9px 22px; height:41px; display:flex; align-items:center; gap:6px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                Upload
              </button>
            </div>
          </div>
          <div id="admin-upload-msg" style="margin-top:10px; font-size:13px; display:none;"></div>
        </div>
        <!-- ===== END ADMIN UPLOAD FORM ===== -->

        <div class="table-section">
          <div style="display:grid; grid-template-columns: 1fr 1fr 1fr 1fr 1fr auto auto; gap:10px; margin-bottom: 12px; align-items:end;">
            <input type="date" id="docs_date_from" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="From">
            <input type="date" id="docs_date_to" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="To">
            <select id="docs_status" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;">
              <option value="">Pending Only</option>
              <option value="all">All Statuses</option>
              <option value="pending_review">Pending Review</option>
              <option value="under_review">Under Review</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
              <option value="requires_revision">Requires Revision</option>
              <option value="pending">Pending (Legacy)</option>
            </select>
            <input type="text" id="docs_type" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="Doc type">
            <input type="text" id="docs_search" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="Search file/user/email">
            <button type="button" class="btn-primary" onclick="applyDocumentFilters()">Apply</button>
            <button type="button" class="btn" onclick="resetDocumentFilters()">Reset</button>
          </div>
          <div id="documents-container" style="background: #f8f9fa; border-radius: 8px; padding: 20px; max-height: 400px; overflow-y: auto;">
            <p style="text-align: center; color: #666;">Loading documents...</p>
          </div>
        </div>
      </div>

      <div id="section-payments" class="section hidden">
        <div class="header">
          <div>
            <h2>Payment Overview</h2>
            <small>Track paid amount, balance due, installments, and turnover</small>
          </div>
        </div>

        <div class="table-section payment-summary-panel">
          <div class="payments-toolbar">
            <div class="payments-toolbar-copy">
              <span class="payments-eyebrow">Payment Summary</span>
              <h3>Payment Ledger</h3>
              <p>Track balances, installment progress, and turnover readiness for every lot from one section.</p>
            </div>
            <div class="payment-search-wrap">
              <label class="payment-search-label" for="paymentSearchInput">Search records</label>
              <input type="text" id="paymentSearchInput" class="payment-search-input" placeholder="Search by user, email, mobile, location, block, lot">
            </div>
          </div>
          <div class="payments-table-shell">
          <table class="accounts-table payments-table">
            <thead>
              <tr>
                <th>Lot Block</th>
                <th>Lot Number</th>
                <th>Location</th>
                <th>Owner</th>
                <th>Status</th>
                <th style="text-align:center;">Total Paid</th>
                <th style="text-align:center;">Balance Due</th>
                <th style="text-align:center;">Lot Price</th>
                <th style="text-align:center;">Actions</th>
              </tr>
            </thead>
            <tbody id="payments-tbody">
              <tr>
                <td colspan="9" style="text-align: center; padding: 30px; color: #6c757d;">Loading payments...</td>
              </tr>
            </tbody>
          </table>
          </div>
        </div>

        <div id="recordPaymentModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:10040;align-items:flex-start;justify-content:center;overflow:auto;padding:20px 12px;">
          <div style="background:#fff; width:95%; max-width:520px; border-radius:10px; padding:18px; box-shadow:0 12px 30px rgba(0,0,0,0.2); max-height:calc(100vh - 40px); overflow-y:auto;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
              <h3 style="margin:0; color:#2d4e1e;">Record Payment</h3>
              <button type="button" onclick="closeRecordPaymentModal()" style="border:none;background:transparent;font-size:22px;cursor:pointer;line-height:1;">&times;</button>
            </div>
            <input type="hidden" id="record-payment-lot-id">
            <div style="display:grid; gap:10px; margin-bottom:10px;">
              <div>
                <label style="display:block; font-size:13px; color:#495057; margin-bottom:6px;">Step 1: Select unpaid month(s)</label>
                <div id="record-payment-month-list" style="max-height:200px; overflow:auto; border:1px solid #dee2e6; border-radius:6px; padding:8px; background:#fff;"></div>
                <div id="record-payment-month-summary" style="margin-top:6px; font-size:12px; color:#6c757d;">Select one or more unpaid months to proceed.</div>
              </div>
              <div style="display:flex; justify-content:flex-end;">
                <button type="button" class="payment-btn payment-btn-primary" onclick="proceedRecordPaymentDetails()">Proceed</button>
              </div>
            </div>
            <div id="record-payment-details-section" style="display:none; border-top:1px solid #e9ecef; padding-top:10px;">
              <div style="font-size:12px; color:#495057; margin-bottom:8px;">Step 2: Confirm payment details and save</div>
              <div style="display:grid; gap:10px;">
              <div>
                <label for="record-payment-amount" style="display:block; font-size:13px; color:#495057; margin-bottom:6px;">Amount (PHP)</label>
                <input id="record-payment-amount" type="number" min="0.01" step="0.01" style="width:100%; padding:9px 10px; border:1px solid #ced4da; border-radius:6px;" placeholder="0.00">
              </div>
              <div>
                <label for="record-payment-date" style="display:block; font-size:13px; color:#495057; margin-bottom:6px;">Payment Date</label>
                <input id="record-payment-date" type="date" style="width:100%; padding:9px 10px; border:1px solid #ced4da; border-radius:6px;">
              </div>
              <div>
                <label for="record-payment-method" style="display:block; font-size:13px; color:#495057; margin-bottom:6px;">Payment Method</label>
                <select id="record-payment-method" style="width:100%; padding:9px 10px; border:1px solid #ced4da; border-radius:6px;">
                  <option value="Cash">Cash</option>
                  <option value="Bank">Bank</option>
                  <option value="GCash">GCash</option>
                </select>
              </div>
              <div>
                <label for="record-payment-remarks" style="display:block; font-size:13px; color:#495057; margin-bottom:6px;">Remarks (optional)</label>
                <textarea id="record-payment-remarks" rows="3" style="width:100%; padding:9px 10px; border:1px solid #ced4da; border-radius:6px; resize:vertical;"></textarea>
              </div>
              </div>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:14px; position:sticky; bottom:0; background:#fff; padding-top:10px; border-top:1px solid #e9ecef;">
              <button type="button" class="payment-btn payment-btn-secondary" onclick="closeRecordPaymentModal()">Cancel</button>
              <button type="button" class="payment-btn payment-btn-primary" onclick="submitRecordPayment()">Save Payment</button>
            </div>
          </div>
        </div>

        <div id="installmentPlanModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:10040;align-items:center;justify-content:center;">
          <div style="background:#fff; width:95%; max-width:520px; border-radius:10px; padding:18px; box-shadow:0 12px 30px rgba(0,0,0,0.2);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
              <h3 style="margin:0; color:#2d4e1e;">Set Installment Plan</h3>
              <button type="button" onclick="closeInstallmentPlanModal()" style="border:none;background:transparent;font-size:22px;cursor:pointer;line-height:1;">&times;</button>
            </div>
            <input type="hidden" id="installment-lot-id">
            <input type="hidden" id="installment-lot-price">
            <div style="display:grid; gap:10px;">
              <div>
                <label for="installment-lot-price-display" style="display:block; font-size:13px; color:#495057; margin-bottom:6px;">Lot Price</label>
                <input id="installment-lot-price-display" type="text" readonly style="width:100%; padding:9px 10px; border:1px solid #d7dde2; border-radius:6px; background:#f8f9fa; color:#495057;">
              </div>
              <div>
                <label for="down-payment-amount" style="display:block; font-size:13px; color:#495057; margin-bottom:6px;">Down Payment Amount</label>
                <input id="down-payment-amount" type="number" min="0" step="0.01" style="width:100%; padding:9px 10px; border:1px solid #ced4da; border-radius:6px;" placeholder="0.00" oninput="recalculateInstallmentPlan()">
              </div>
              <div>
                <label for="installment-term-years" style="display:block; font-size:13px; color:#495057; margin-bottom:6px;">How Many Years to Pay</label>
                <input id="installment-term-years" type="number" min="1" max="5" step="1" style="width:100%; padding:9px 10px; border:1px solid #ced4da; border-radius:6px;" placeholder="e.g. 5" oninput="recalculateInstallmentPlan()">
              </div>
              <div>
                <label for="installment-remaining-balance" style="display:block; font-size:13px; color:#495057; margin-bottom:6px;">Remaining Balance After Down Payment</label>
                <input id="installment-remaining-balance" type="text" readonly style="width:100%; padding:9px 10px; border:1px solid #d7dde2; border-radius:6px; background:#f8f9fa; color:#495057;">
              </div>
              <div>
                <label for="installment-amount" style="display:block; font-size:13px; color:#495057; margin-bottom:6px;">Monthly Installment Amount</label>
                <input id="installment-amount" type="text" readonly style="width:100%; padding:9px 10px; border:1px solid #d7dde2; border-radius:6px; background:#f8f9fa; color:#14532d; font-weight:600;" placeholder="Auto-calculated">
                <small style="display:block; color:#6c757d; margin-top:6px; font-size:12px;">Computed as remaining balance divided by total months.</small>
              </div>
              <div>
                <label for="installment-due-day" style="display:block; font-size:13px; color:#495057; margin-bottom:6px;">Pay Every What Day of the Month</label>
                <input id="installment-due-day" type="number" min="1" max="31" step="1" style="width:100%; padding:9px 10px; border:1px solid #ced4da; border-radius:6px;" placeholder="1-31">
              </div>
              <div>
                <label for="installment-deadline" style="display:block; font-size:13px; color:#495057; margin-bottom:6px;">Next Payment Deadline</label>
                <input id="installment-deadline" type="date" style="width:100%; padding:9px 10px; border:1px solid #ced4da; border-radius:6px;">
                <small style="display:block; color:#6c757d; margin-top:6px; font-size:12px;">Leave blank to auto-generate the next due date from the monthly due day.</small>
              </div>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:14px;">
              <button type="button" class="payment-btn payment-btn-secondary" onclick="closeInstallmentPlanModal()">Cancel</button>
              <button type="button" class="payment-btn" onclick="submitInstallmentPlan()">Save Installment</button>
            </div>
          </div>
        </div>

        <div id="paymentHistoryModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:10040;align-items:center;justify-content:center;">
          <div style="background:#fff; width:96%; max-width:840px; border-radius:10px; padding:18px; box-shadow:0 12px 30px rgba(0,0,0,0.2); max-height:88vh; overflow:auto;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
              <h3 style="margin:0; color:#2d4e1e;">Payment History</h3>
              <button type="button" onclick="closePaymentHistoryModal()" style="border:none;background:transparent;font-size:22px;cursor:pointer;line-height:1;">&times;</button>
            </div>
            <div id="payment-history-content" style="border:1px solid #e9ecef; border-radius:8px; overflow:hidden;">
              <div style="padding:16px; color:#6c757d; text-align:center;">Loading payment history...</div>
            </div>
          </div>
        </div>

        <div id="turnoverModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:10040;align-items:center;justify-content:center;">
          <div style="background:#fff; width:95%; max-width:540px; border-radius:10px; padding:18px; box-shadow:0 12px 30px rgba(0,0,0,0.2);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
              <h3 style="margin:0; color:#2d4e1e;">Turnover and Title Claim</h3>
              <button type="button" onclick="closeTurnoverModal()" style="border:none;background:transparent;font-size:22px;cursor:pointer;line-height:1;">&times;</button>
            </div>
            <input type="hidden" id="turnover-lot-id">
            <div style="display:grid; gap:10px;">
              <div style="font-size:13px; color:#495057; background:#f8f9fa; border:1px solid #e9ecef; border-radius:6px; padding:10px 12px;">
                Fully paid clients should proceed to the <strong>Main Office</strong> to claim the ownership title.
              </div>
              <div>
                <label for="turnover-date" style="display:block; font-size:13px; color:#495057; margin-bottom:6px;">Turnover Date</label>
                <input id="turnover-date" type="date" style="width:100%; padding:9px 10px; border:1px solid #ced4da; border-radius:6px;">
              </div>
              <div>
                <label style="display:flex; align-items:center; gap:8px; font-size:13px; color:#495057; cursor:pointer;">
                  <input id="turnover-title-released" type="checkbox">
                  Ownership title already claimed/released at Main Office
                </label>
              </div>
              <div>
                <label for="turnover-remarks" style="display:block; font-size:13px; color:#495057; margin-bottom:6px;">Remarks</label>
                <textarea id="turnover-remarks" rows="3" style="width:100%; padding:9px 10px; border:1px solid #ced4da; border-radius:6px; resize:vertical;"></textarea>
              </div>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:14px;">
              <button type="button" class="payment-btn payment-btn-secondary" onclick="closeTurnoverModal()">Cancel</button>
              <button type="button" class="payment-btn" onclick="submitTurnoverUpdate()">Save Turnover</button>
            </div>
          </div>
        </div>
      </div>

      <div id="section-lot-owners" class="section hidden">
        <div class="header">
          <div>
            <h2>Lot Owners</h2>
            <small>Manage lot owners and their payment status</small>
          </div>
          <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <label for="lot-owner-location-filter" style="font-size:13px; color:#495057; font-weight:600;">Filter by Location</label>
            <select id="lot-owner-location-filter" style="padding:8px 10px; border:1px solid #ced4da; border-radius:6px; min-width:190px;">
              <option value="">All Locations</option>
            </select>
            <button type="button" id="refresh-lot-owners-btn" class="btn-small" style="padding:8px 12px;">Apply</button>
          </div>
        </div>

        <div class="table-section">
          <h3>Lot Owners List</h3>
          <table class="accounts-table" style="width: 100%; border-collapse: collapse; margin-top: 15px;">
            <thead>
              <tr>
                <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0; background: #f8f9fa;">Owner Name</th>
                <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0; background: #f8f9fa;">Email</th>
                <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0; background: #f8f9fa;">Mobile</th>
                <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0; background: #f8f9fa;">Lot Details</th>
                <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0; background: #f8f9fa;">Payment Status</th>
                <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0; background: #f8f9fa;">Actions</th>
              </tr>
            </thead>
            <tbody id="lot-owners-tbody">
              <tr>
                <td colspan="6" style="text-align: center; padding: 20px; color: #666;">Loading lot owners...</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div id="section-lot-status" class="section hidden">
        <div class="header">
          <div>
            <h2>Lot Status</h2>
            <small>Review surrendered lot records and history.</small>
          </div>
          <?php if ($unreadLotStatusCount > 0): ?>
            <button onclick="markAllLotStatusAsReadAdmin()" style="background-color: #1e7c34; color: white; padding: 10px 15px; border: none; border-radius: 4px; font-weight: 500; cursor: pointer; transition: background-color 0.2s;">
              Mark All as Read
            </button>
          <?php endif; ?>
        </div>

        <div class="table-section">
          <h3>Surrendered Lots</h3>
          <table class="accounts-table" style="width: 100%; border-collapse: collapse; margin-top: 15px;">
            <thead>
              <tr>
                <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0; background: #f8f9fa;">Date</th>
                <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0; background: #f8f9fa;">Lot</th>
                <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0; background: #f8f9fa;">Client</th>
                <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0; background: #f8f9fa;">Paid / Refund</th>
                <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0; background: #f8f9fa;">Remarks</th>
              </tr>
            </thead>
            <tbody id="lot-status-tbody">
              <tr>
                <td colspan="5" style="text-align:center; padding:20px; color:#666;">Loading surrendered lots...</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div id="ownerDetailsModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:10040;align-items:center;justify-content:center;">
        <div style="background:#fff; width:95%; max-width:520px; border-radius:10px; padding:18px; box-shadow:0 12px 30px rgba(0,0,0,0.2);">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <h3 style="margin:0; color:#2d4e1e;">Owner Details</h3>
            <button type="button" onclick="closeOwnerDetailsModal()" style="border:none;background:transparent;font-size:22px;cursor:pointer;line-height:1;">&times;</button>
          </div>
          <div id="owner-details-content" style="display:grid; gap:10px;">
            <div style="padding:10px 12px; border:1px solid #e9ecef; border-radius:6px; color:#6c757d;">Loading details...</div>
          </div>
          <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:14px;">
            <button type="button" class="payment-btn payment-btn-secondary" onclick="closeOwnerDetailsModal()">Close</button>
          </div>
        </div>
      </div>

    </div> </body>




    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="assets/js/alert-modal.js"></script>

  <script>
  // Admin Dashboard JavaScript v1.3 - CRITICAL FIX - March 8, 2026
  // Fixed: Canvas positioning issue - wrapped image and canvas together for proper overlay
  // Fixed: Lots auto-load, button text "Update Status", added drawing debug logs
  // ================================
  // MAIN NAVIGATION & INITIAL SETUP
  // ================================
  document.addEventListener('DOMContentLoaded', function() {
    // Section navigation
    const sections = [
      'section-dashboard',
      'section-accounts',
      'section-lots',
      'section-viewings',
      'section-analytics',
      'section-reports',
      'section-documents',
      'section-payments',
      'section-lot-owners',
      'section-lot-status',
      'section-notifications',
      'section-audit-logs'
    ];
    
    function showSection(targetId) {
      sections.forEach(sectionId => {
        const section = document.getElementById(sectionId);
        if (section) {
            const willShow = sectionId === targetId;
            // use `active` class like user dashboard for smooth transitions
            section.classList.toggle('active', willShow);
            // remove any legacy `hidden` class if present
            if (section.classList.contains('hidden')) section.classList.remove('hidden');
          }
      });
      
      // Update active nav link
      document.querySelectorAll('[data-target]').forEach(link => {
        link.classList.toggle('active', link.dataset.target === targetId);
      });

      // Load data based on section
      if (targetId === 'section-lots') {
        loadLocations();
        loadLots('');
      } else if (targetId === 'section-analytics') {
        loadLocations();
        loadAnalyticsData();
      } else if (targetId === 'section-reports') {
        loadPrintableReports();
      } else if (targetId === 'section-documents') {
        onDocumentsSectionShow();
      } else if (targetId === 'section-notifications') {
        loadNotifications();
      } else if (targetId === 'section-audit-logs') {
        loadAuditLogs();
      } else if (targetId === 'section-payments') {
        loadPayments();
      } else if (targetId === 'section-lot-owners') {
        loadLotOwnerLocationOptions();
        loadLotOwners();
      } else if (targetId === 'section-lot-status') {
        loadSurrenderedLots();
      }
    }

    // Handle navigation clicks
    document.querySelectorAll('[data-target]').forEach(link => {
      link.addEventListener('click', function(e) {
        e.preventDefault();
        showSection(this.dataset.target);
      });
    });

    // Add Analytics navigation dynamically (if not present)
    const viewingsNav = document.querySelector('[data-target="section-viewings"]');
    if (viewingsNav && !document.querySelector('[data-target="section-analytics"]')) {
      const analyticsNav = document.createElement('a');
      analyticsNav.setAttribute('data-target', 'section-analytics');
      analyticsNav.innerHTML = `
        <svg width="24" height="24" fill="white" viewBox="0 0 24 24" class="nav-icon">
          <path d="M16 6l2.29 2.29-4.88 4.88-4-4L2 16.59 3.41 18l6-6 4 4 6.3-6.29L22 12V6z"/>
        </svg>
        <span>Analytics</span>
      `;
      viewingsNav.parentNode.insertBefore(analyticsNav, viewingsNav.nextSibling);
      
      analyticsNav.addEventListener('click', function(e) {
        e.preventDefault();
        showSection('section-analytics');
      });
    }

    // Show dashboard by default
    showSection('section-dashboard');

    // Lots location dropdown
    const locationSelect = document.getElementById('location_id');
    if (locationSelect) {
      locationSelect.addEventListener('change', function() {
        loadLots(this.value);
      });
    }

    const topAgentRankModeSelect = document.getElementById('top_agents_rank_mode');
    if (topAgentRankModeSelect) {
      topAgentRankModeSelect.addEventListener('change', function() {
        loadTopAgents(1);
      });
    }

    const topAgentSalesScopeSelect = document.getElementById('top_agents_sales_scope');
    if (topAgentSalesScopeSelect) {
      topAgentSalesScopeSelect.addEventListener('change', function() {
        loadTopAgents(1);
      });
    }

    const analyticsSalesPeriodSelect = document.getElementById('analytics_sales_period');
    if (analyticsSalesPeriodSelect) {
      analyticsSalesPeriodSelect.addEventListener('change', function() {
        loadAnalyticsData();
      });
    }

    const reportSalesPeriodSelect = document.getElementById('report_sales_period');
    if (reportSalesPeriodSelect) {
      reportSalesPeriodSelect.addEventListener('change', function() {
        applyReportSalesPeriodPreset();
        loadPrintableReports();
      });
      applyReportSalesPeriodPreset();
    }

    // Initial loads
    loadNotifications();
    refreshBadges();

    // Auto-refresh every 20 seconds
    setInterval(() => {
      loadNotifications();
      refreshBadges();

      const docsSection  = document.getElementById('section-documents');
      const auditSection = document.getElementById('section-audit-logs');

      if (docsSection && docsSection.classList.contains('active')) {
        loadDocuments();
      }
      if (auditSection && auditSection.classList.contains('active')) {
        loadAuditLogs();
      }
    }, 20000);

    // ========= Edit Account modal submit =========
    function parseJsonResponse(response) {
      return response.text().then(text => {
        const raw = (text || '').trim();
        if (!raw) {
          throw new Error('Server returned an empty response.');
        }
        try {
          return JSON.parse(raw);
        } catch (err) {
          throw new Error('Server returned invalid JSON.');
        }
      });
    }

    const editAccountForm = document.getElementById('editAccountForm');
    if (editAccountForm) {
      editAccountForm.addEventListener('submit', function (e) {
        e.preventDefault();

        const formData = new FormData(editAccountForm);
        const type = formData.get('account_type');

        if (type === 'admin') {
          formData.append('account_action', 'update');
        } else if (type === 'agent') {
          formData.append('agent_action', 'update');
        } else if (type === 'user') {
          formData.append('user_action', 'update');
        }

        fetch(window.location.pathname, {
          method: 'POST',
          body: formData
        })
        .then(parseJsonResponse)
        .then(res => {
          if (res.success) {
            alert(res.message || 'Account updated!');
            closeEditAccountModal();
            location.reload();
          } else {
            alert('Failed to update account: ' + (res.error || res.message || 'Unknown error'));
          }
        })
        .catch(err => {
          console.error('Update fetch error:', err);
          alert('Failed to update account: ' + err.message);
        });
      });
    }

    // ========= Edit Lot modal submit =========
    const editLotForm = document.getElementById('editLotForm');
    if (editLotForm) {
      editLotForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(editLotForm);
        const lotPriceValue = formData.get('lot_price');
        const commissionValue = formData.get('commission_amount');
        if (lotPriceValue !== null) {
          formData.set('lot_price', normalizeLotPriceValue(lotPriceValue));
        }
        if (commissionValue !== null) {
          formData.set('commission_amount', normalizeLotPriceValue(commissionValue));
        }
        formData.append('action', 'save');
        fetch(window.location.pathname, { method: 'POST', body: formData })
          .then(r => r.json())
          .then(res => {
            if (res.success) {
              alert('Lot updated!');
              closeEditLotModal();
              const locSel = document.getElementById('location_id');
              loadLots(locSel ? locSel.value : '');
            } else {
              alert('Failed to update lot: ' + (res.error || 'Unknown error'));
            }
          })
          .catch(() => alert('Failed to update lot.'));
      });

      bindLotPriceFormatter(document.getElementById('lot_price'));
    }

    // ========= Agent account create (AJAX) =========
    const agentForm = document.getElementById('agent-account-form');
    if (agentForm) {
      agentForm.addEventListener('submit', async function(e) {
        e.preventDefault();

        const pass   = document.getElementById('agent_password');
        const confirm = document.getElementById('agent_confirm_password');
        const error  = document.getElementById('agent-password-error');

        if (pass && confirm && error && pass.value !== confirm.value) {
          error.style.display = 'block';
          return;
        } else if (error) {
          error.style.display = 'none';
        }

        const formData = new FormData(agentForm);

        try {
          const response = await fetch(window.location.pathname, {
            method: "POST",
            body: formData
          });
          const result = await response.json();

          if (result.success) {
            alert(result.message || "✅ Agent account created successfully!");
            agentForm.reset();
            if (error) error.style.display = 'none';
          } else {
            alert("❌ Error: " + (result.error || result.message || "Failed to create account"));
          }
        } catch (err) {
          alert("❌ Request failed.");
          console.error(err);
        }
      });
    }

    // ========= Admin account create (AJAX) =========
    const adminForm = document.getElementById('admin-account-form');
    if (adminForm) {
      const adminPass    = document.getElementById('admin_password');
      const adminConfirm = document.getElementById('admin_confirm_password');
      const adminError   = document.getElementById('admin-password-error');

      if (adminPass && adminConfirm && adminError) {
        // live feedback
        function validateAdminPasswords() {
          if (adminPass.value && adminConfirm.value && adminPass.value !== adminConfirm.value) {
            adminError.style.display = 'block';
            adminConfirm.setCustomValidity('Passwords do not match');
          } else {
            adminError.style.display = 'none';
            adminConfirm.setCustomValidity('');
          }
        }

        adminPass.addEventListener('input', validateAdminPasswords);
        adminConfirm.addEventListener('input', validateAdminPasswords);

        adminForm.addEventListener('submit', function(e) {
          e.preventDefault();

          validateAdminPasswords();
          if (adminConfirm.validationMessage) {
            adminConfirm.reportValidity();
            return;
          }

          const formData = new FormData(adminForm);
          if (!formData.get('account_action')) {
            formData.append('account_action', 'add');
          }

          fetch(window.location.pathname, {
            method: 'POST',
            body: formData
          })
          .then(r => r.json())
          .then(res => {
            if (res.success) {
              alert(res.message || 'Admin account created successfully!');
              adminForm.reset();
              adminError.style.display = 'none';
            } else {
              alert(res.error || res.message || 'Failed to create admin account.');
            }
          })
          .catch(err => {
            alert('Request failed: ' + err.message);
          });
        });
      }
    }

    // ========= User password live check =========
    (function () {
      const form    = document.getElementById('user-account-form');
      const pass    = document.getElementById('user_password');
      const confirm = document.getElementById('user_confirm_password');
      const error   = document.getElementById('user-password-error');

      if (!form || !pass || !confirm || !error) return;

      function validateUserPasswords() {
        if (pass.value && confirm.value && pass.value !== confirm.value) {
          error.style.display = 'block';
          confirm.setCustomValidity('Passwords do not match');
        } else {
          error.style.display = 'none';
          confirm.setCustomValidity('');
        }
      }

      pass.addEventListener('input',    validateUserPasswords);
      confirm.addEventListener('input', validateUserPasswords);

      form.addEventListener('submit', async function (e) {
        e.preventDefault();
        validateUserPasswords();
        if (confirm.validationMessage) {
          confirm.reportValidity();
          return;
        }

        const submitBtn = form.querySelector('button[type="submit"]');
        const originalBtnText = submitBtn ? submitBtn.textContent : '';
        if (submitBtn) {
          submitBtn.disabled = true;
          submitBtn.textContent = 'Creating...';
        }

        try {
          const formData = new FormData(form);
          const response = await fetch(window.location.pathname, {
            method: 'POST',
            body: formData
          });
          const res = await parseJsonResponse(response);

          if (res.success) {
            alert(res.message || 'User account created successfully!');
            form.reset();
            error.style.display = 'none';
            location.reload();
          } else {
            alert('Failed to create user account: ' + (res.error || res.message || 'Unknown error'));
          }
        } catch (err) {
          console.error('Create user account failed:', err);
          alert('Failed to create user account: ' + (err.message || 'Request failed.'));
        } finally {
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = originalBtnText;
          }
        }
      });
    })();

    // ========= View Client modal close handlers =========
    const viewModal = document.getElementById('viewClientModal');
    if (viewModal) {
      viewModal.addEventListener('click', (e) => {
        if (e.target === viewModal) closeViewClientModal();
      });
    }
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeViewClientModal();
    });

    // expose globals
    window.loadLocations           = loadLocations;
    window.loadLots                = loadLots;
    window.saveLot                 = saveLot;
    window.editLot                 = editLot;
    window.saveEdit                = saveEdit;
    window.deleteLot               = deleteLot;
    window.addNewLot               = addNewLot;
    window.cancelAdd               = cancelAdd;
    window.cancelEdit              = cancelEdit;
    window.showAccountType         = showAccountType;
    window.confirmLogout           = confirmLogout;
    window.applyAnalyticsFilters   = applyAnalyticsFilters;
    window.loadTopAgents           = loadTopAgents;
    window.exportAnalytics         = exportAnalytics;
    window.loadDocuments           = loadDocuments;
    window.loadNotifications       = loadNotifications;
    window.loadAuditLogs           = loadAuditLogs;
    window.viewProfile             = viewProfile;
    window.closeViewClientModal    = closeViewClientModal;
    window.editAccount             = editAccount;
    window.closeEditAccountModal   = closeEditAccountModal;
    window.openEditLotModal        = openEditLotModal;
    window.closeEditLotModal       = closeEditLotModal;
    window.bulkDeleteLots          = bulkDeleteLots;
    window.openPinModal            = openPinModal;
    window.closePinModal           = closePinModal;
    window.toggleDrawingMode       = toggleDrawingMode;
    window.savePinLocation         = savePinLocation;
    window.selectLotStatus         = selectLotStatus;
    window.openAddLocationModal    = openAddLocationModal;
    window.closeAddLocationModal   = closeAddLocationModal;
    window.saveNewLocation         = saveNewLocation;
    window.deleteSelectedLocation  = deleteSelectedLocation;

    const userAccountsSearch = document.getElementById('userAccountsSearch');
    if (userAccountsSearch) {
      userAccountsSearch.addEventListener('input', function() {
        const query = userAccountsSearch.value.trim().toLowerCase();
        document.querySelectorAll('#section-accounts .user-account-row').forEach(row => {
          const text = row.textContent.toLowerCase();
          row.style.display = !query || text.includes(query) ? '' : 'none';
        });
      });
    }

    const paymentSearchInput = document.getElementById('paymentSearchInput');
    if (paymentSearchInput) {
      paymentSearchInput.addEventListener('input', function() {
        loadPayments();
      });
    }
  });

  // ===========================
  // BADGES (Notifications / Docs)
  // ===========================
  function updateBadge(el, count) {
    if (!el) return;
    if (count > 0) {
      el.style.display = 'inline-block';
      el.textContent = count > 99 ? '99+' : count;
    } else {
      el.style.display = 'none';
    }
  }

  function refreshBadges() {
    const notifBadge = document.getElementById('notifications-badge');
    const docsBadge  = document.getElementById('documents-badge');

    if (notifBadge) {
      fetch(window.location.pathname + '?fetch=notifications_count')
        .then(r => r.json())
        .then(data => updateBadge(notifBadge, data.count || 0))
        .catch(() => {});
    }

    if (docsBadge) {
      fetch(window.location.pathname + '?fetch=documents_count')
        .then(r => r.json())
        .then(data => updateBadge(docsBadge, data.count || 0))
        .catch(() => {});
    }
  }

  // ===========================
  // LOTS MANAGEMENT FUNCTIONS
  // ===========================
  function loadLocations(selectedLocationId = '') {
    fetch(window.location.pathname + '?fetch=locations')
      .then(response => response.json())
      .then(locations => {
        const selects = ['location_id', 'analytics_location', 'report_location'];
        selects.forEach(selectId => {
          const select = document.getElementById(selectId);
          if (!select) return;

          const isAnalytics = selectId === 'analytics_location';
          const isReport = selectId === 'report_location';
          const normalizedSelected = String(selectedLocationId || '');
          let optionsHtml = (isAnalytics || isReport)
            ? '<option value="">All Locations</option>'
            : `<option value="" disabled${normalizedSelected ? '' : ' selected'}>Please select a location first</option>`;

          optionsHtml += (locations || []).map(location => {
            const id = String(location.id);
            const selectedAttr = (!isAnalytics && !isReport && normalizedSelected && normalizedSelected === id) ? ' selected' : '';
            return `<option value="${id}"${selectedAttr}>${location.location_name}</option>`;
          }).join('');

          select.innerHTML = optionsHtml;

          if (!isAnalytics && !isReport && normalizedSelected) {
            select.value = normalizedSelected;
          } else if (!isAnalytics && !isReport) {
            select.value = '';
          }
        });
      })
      .catch(error => console.error('Error loading locations:', error));
  }

  function loadLots(locationId = '') {
    console.log('Loading lots for location:', locationId); // Debug log
    const normalizedLocationId = String(locationId || '').trim();
    fetch(`${window.location.pathname}?fetch=lots&location_id=${locationId}`)
      .then(response => response.json())
      .then(data => {
        console.log('Lots data received:', data); // Debug log
        const tbody = document.getElementById('lots-table-body');
        const newRow = document.getElementById('new-row');
        if (!tbody) return;

        if (newRow) newRow.remove();
        
        tbody.innerHTML = '';

                if (!normalizedLocationId) {
                  tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; color: #666;">Please select a location first.</td></tr>';
                } else if (!data.length) {
          tbody.innerHTML = '<tr><td colspan="8" style="text-align: center;">No lots available.</td></tr>';
        } else {
          data.forEach(lot => {
            const workflowStage = lot.workflow_stage || lot.status || 'Available';
            const row = tbody.insertRow();
            row.setAttribute('data-id', lot.id);
            const formattedPrice = lot.lot_price ? '₱' + parseFloat(lot.lot_price).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : 'N/A';
            const formattedCommission = lot.commission_amount && Number(lot.commission_amount) > 0
              ? '₱' + parseFloat(lot.commission_amount).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})
              : '—';
            const formattedSize = lot.lot_size ? parseFloat(lot.lot_size).toLocaleString() + ' sqm' : 'N/A';
            row.innerHTML = `
              <td><input type="checkbox" class="lot-checkbox" value="${lot.id}"></td>
              <td>${lot.block_number}</td>
              <td>${lot.lot_number}</td>
              <td>${formattedSize}</td>
              <td>${formattedPrice}</td>
              <td>${formattedCommission}</td>
              <td>${workflowStage}</td>
              <td>
                <div class="lots-action-buttons">
                  <button onclick='openPinModal(${lot.id}, ${JSON.stringify(lot)})' class="lot-action-btn lot-action-btn-blueprint">Update Status</button>
                  <button onclick='openEditLotModal(${JSON.stringify(lot)})' class="lot-action-btn lot-action-btn-edit" style="background:#2196f3; color:#fff; border:1px solid #2196f3;">Edit</button>
                  <button onclick="deleteLot(${lot.id})" class="lot-action-btn lot-action-btn-delete" style="background:#dc3545; color:#fff; border:1px solid #dc3545;">Delete</button>
                </div>
              </td>
            `;
          });
        }

        if (newRow) tbody.appendChild(newRow);
      })
      .catch(error => {
        console.error('Error loading lots:', error);
        alert('Error loading lots. Check console for details.');
      });
  }

  function normalizeLotPriceValue(value) {
    const cleaned = String(value ?? '').replace(/[^0-9.]/g, '');
    const firstDot = cleaned.indexOf('.');
    if (firstDot === -1) return cleaned;
    return cleaned.slice(0, firstDot + 1) + cleaned.slice(firstDot + 1).replace(/\./g, '');
  }

  function formatLotPriceDisplay(value) {
    const normalized = normalizeLotPriceValue(value);
    if (normalized === '') return '';
    const amount = Number(normalized);
    if (!Number.isFinite(amount)) return '';
    return amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function bindLotPriceFormatter(input) {
    if (!input || input.dataset.priceFormatterBound === '1') return;
    input.dataset.priceFormatterBound = '1';

    input.addEventListener('focus', function() {
      const raw = normalizeLotPriceValue(input.value);
      if (raw !== '') input.value = raw;
    });

    input.addEventListener('blur', function() {
      input.value = formatLotPriceDisplay(input.value);
    });

    input.value = formatLotPriceDisplay(input.value);
  }

  function saveLot() {
    const fields = ['block_number', 'lot_number', 'lot_size', 'lot_price', 'commission_amount', 'status'];
    const locationId = document.getElementById('location_id').value;
    
    const data = {};
    let isValid = true;
    
    fields.forEach(field => {
      const value = document.getElementById(field).value;
      if (!value || (field.includes('number') && isNaN(value))) {
        isValid = false;
      }
      data[field] = (field === 'lot_price' || field === 'commission_amount') ? normalizeLotPriceValue(value) : value;
    });

    if (!isValid || !locationId) {
      alert('Please fill out all fields correctly and select a location.');
      return;
    }

    const formData = new FormData();
    formData.append('action', 'save');
    Object.keys(data).forEach(key => formData.append(key, data[key]));
    formData.append('location_id', locationId);
    formData.append('payment_type', 'Not Applicable');
    formData.append('payment_amount', '');
    formData.append('payment_deadline', '');

    fetch(window.location.pathname, { method: 'POST', body: formData })
      .then(response => response.json())
      .then(result => {
        if (result.success) {
          showLotMessage('Lot added successfully!', true);
          loadLots(locationId);
          cancelAdd();
        } else {
          alert('Error: ' + (result.error || result.message || 'Unknown error'));
        }
      })
      .catch(error => console.error('Error:', error));
  }

  async function deleteLot(id) {
    const proceed = await showConfirmModal('Are you sure you want to delete this lot?');
    if (!proceed) return;

    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('lot_id', id);

    fetch(window.location.pathname, { method: 'POST', body: formData })
      .then(response => response.json())
      .then(result => {
        if (result.success) {
          showLotMessage('Lot deleted successfully!', true);
          const locSel = document.getElementById('location_id');
          loadLots(locSel ? locSel.value : '');
        } else {
          alert('Error: ' + result.error);
        }
      })
      .catch(error => console.error('Error:', error));
  }

  function editLot(button) {
    const row = button.closest('tr');
    const cells = row.querySelectorAll('td');

    for (let i = 0; i < 5; i++) {
      cells[i].setAttribute('data-original', cells[i].innerText);
    }

    cells[0].innerHTML = `<input type="text" value="${cells[0].getAttribute('data-original')}">`;
    cells[1].innerHTML = `<input type="text" value="${cells[1].getAttribute('data-original')}">`;
    cells[2].innerHTML = `<input type="text" value="${cells[2].getAttribute('data-original')}">`;
    const inlinePrice = formatLotPriceDisplay(cells[3].getAttribute('data-original'));
    cells[3].innerHTML = `<input type="text" value="${inlinePrice}">`;
    cells[4].innerHTML = `
      <select>
        <option value="Available" ${cells[4].getAttribute('data-original') === 'Available' ? 'selected' : ''}>Available</option>
        <option value="Sold" ${cells[4].getAttribute('data-original') === 'Sold' ? 'selected' : ''}>Sold</option>
        <option value="Reserved" ${cells[4].getAttribute('data-original') === 'Reserved' ? 'selected' : ''}>Reserved</option>
      </select>
    `;
    cells[5].innerHTML = '<button onclick="saveEdit(this)">Save</button><button onclick="cancelEdit(this)">Cancel</button>';

    const inlinePriceInput = cells[3].querySelector('input');
    bindLotPriceFormatter(inlinePriceInput);
  }

  function saveEdit(button) {
    const row = button.closest('tr');
    const id = row.getAttribute('data-id');
    const inputs = row.querySelectorAll('input, select');
    const locationId = document.getElementById('location_id').value;

    if (!locationId) {
      showLotMessage('Please select a location.', false);
      return;
    }

    const formData = new FormData();
    formData.append('action', 'save');
    formData.append('lot_id', id);
    formData.append('block_number', inputs[0].value);
    formData.append('lot_number', inputs[1].value);
    formData.append('lot_size', inputs[2].value);
    formData.append('lot_price', normalizeLotPriceValue(inputs[3].value));
    formData.append('status', inputs[4].value);
    formData.append('location_id', locationId);

    fetch(window.location.pathname, { method: 'POST', body: formData })
      .then(response => response.json())
      .then(result => {
        if (result.success) {
          showLotMessage('Lot updated successfully!', true);
          setTimeout(() => loadLots(locationId), 1000);
        } else {
          showLotMessage('Error: ' + result.error, false);
        }
      })
      .catch(error => console.error('Error:', error));
  }

  function addNewLot() {
    const newRow = document.getElementById('new-row');
    if (newRow) {
      newRow.style.display = 'table-row';
      const status = document.getElementById('status');
      if (status) status.value = 'Available';
      bindLotPriceFormatter(document.getElementById('lot_price'));
    }
  }

  function cancelAdd() {
    const newRow = document.getElementById('new-row');
    if (!newRow) return;
    newRow.style.display = 'none';
    newRow.querySelectorAll('input').forEach(input => input.value = '');
    const status = document.getElementById('status');
    if (status) status.value = 'Available';
  }

  function togglePaymentFieldsByStatus(status) {
    const paymentTypeSelect = document.getElementById('payment_type');
    const paymentDeadlineInput = document.getElementById('payment_deadline');
    if (!paymentTypeSelect) return;

    if (status === 'Available') {
      paymentTypeSelect.value = 'Not Applicable';
      paymentTypeSelect.disabled = true;
      if (paymentDeadlineInput) {
        paymentDeadlineInput.style.display = 'none';
        paymentDeadlineInput.value = '';
      }
      toggleDownPaymentField('Not Applicable');
      return;
    }

    if (status === 'Paid') {
      paymentTypeSelect.value = 'Fully Paid';
      paymentTypeSelect.disabled = true;
      if (paymentDeadlineInput) {
        paymentDeadlineInput.style.display = 'none';
        paymentDeadlineInput.value = '';
      }
      toggleDownPaymentField('Fully Paid');
      return;
    }

    paymentTypeSelect.disabled = false;
    if (!paymentTypeSelect.value) {
      paymentTypeSelect.value = 'Not Applicable';
    }
    toggleDownPaymentField(paymentTypeSelect.value);
  }

  function toggleDownPaymentField(paymentType) {
    const paymentAmountInput = document.getElementById('payment_amount');
    const paymentDeadlineInput = document.getElementById('payment_deadline');
    const paymentAmountWrap = document.getElementById('payment_amount_wrap');
    const paymentDeadlineWrap = document.getElementById('payment_deadline_wrap');
    if (!paymentAmountInput) return;

    if (paymentType === 'Down Payment') {
      if (paymentAmountWrap) paymentAmountWrap.style.display = 'block';
      paymentAmountInput.required = true;
      if (paymentDeadlineInput) {
        if (paymentDeadlineWrap) paymentDeadlineWrap.style.display = 'block';
      }
    } else {
      if (paymentAmountWrap) paymentAmountWrap.style.display = 'none';
      paymentAmountInput.required = false;
      paymentAmountInput.value = '';
      if (paymentDeadlineInput) {
        if (paymentDeadlineWrap) paymentDeadlineWrap.style.display = 'none';
        paymentDeadlineInput.value = '';
      }
    }
  }

  function toggleEditDownPaymentField(paymentType) {
    const paymentAmountInput = document.getElementById('edit_payment_amount');
    const paymentDeadlineInput = document.getElementById('edit_payment_deadline');
    const paymentAmountGroup = document.getElementById('edit_payment_amount_group');
    const paymentDeadlineGroup = document.getElementById('edit_payment_deadline_group');
    if (!paymentAmountInput) return;

    if (paymentType === 'Down Payment') {
      if (paymentAmountGroup) paymentAmountGroup.style.display = 'block';
      paymentAmountInput.required = true;
      if (paymentDeadlineInput) {
        if (paymentDeadlineGroup) paymentDeadlineGroup.style.display = 'block';
      }
    } else {
      if (paymentAmountGroup) paymentAmountGroup.style.display = 'none';
      paymentAmountInput.required = false;
      paymentAmountInput.value = '';
      if (paymentDeadlineInput) {
        if (paymentDeadlineGroup) paymentDeadlineGroup.style.display = 'none';
        paymentDeadlineInput.value = '';
      }
    }
  }

  function toggleEditPaymentFieldsByStatus(status) {
    const paymentTypeSelect = document.getElementById('edit_payment_type');
    const paymentDeadlineInput = document.getElementById('edit_payment_deadline');
    if (!paymentTypeSelect) return;

    if (status === 'Available') {
      paymentTypeSelect.value = 'Not Applicable';
      paymentTypeSelect.disabled = true;
      if (paymentDeadlineInput) {
        paymentDeadlineInput.style.display = 'none';
        paymentDeadlineInput.value = '';
      }
      toggleEditDownPaymentField('Not Applicable');
      return;
    }

    paymentTypeSelect.disabled = false;
    if (paymentDeadlineInput) {
      paymentDeadlineInput.style.display = 'block';
    }
    if (paymentTypeSelect.value === 'Not Applicable') {
      paymentTypeSelect.value = 'Fully Paid';
    }
    toggleEditDownPaymentField(paymentTypeSelect.value);
  }

  function cancelEdit() {
    const locSel = document.getElementById('location_id');
    loadLots(locSel ? locSel.value : '');
  }

  function showLotMessage(msg, success = true) {
    const msgDiv = document.getElementById('lot-message');
    if (!msgDiv) return;
    msgDiv.textContent = msg;
    msgDiv.style.display = 'block';
    msgDiv.style.background = success ? '#d4edda' : '#f8d7da';
    msgDiv.style.color = success ? '#155724' : '#721c24';
    msgDiv.style.border = success ? '1px solid #c3e6cb' : '1px solid #f5c6cb';
    setTimeout(() => msgDiv.style.display = 'none', 3000);
  }

  // Backward-compatible notifier used by multiple sections.
  function showMessage(msg, success = true) {
    const msgDiv = document.getElementById('lot-message');
    if (msgDiv) {
      showLotMessage(msg, success);
      return;
    }
    if (!success) {
      alert(msg);
    } else {
      console.log(msg);
    }
  }

  // ===========================
  // DOCUMENT REVIEW FUNCTIONS
  // ===========================

  // Populate user dropdown for admin upload
  function loadAdminUploadUserList() {
    fetch(window.location.pathname + '?fetch=user_list')
      .then(r => r.json())
      .then(users => {
        const sel = document.getElementById('admin_upload_user_id');
        if (!sel) return;
        sel.innerHTML = '<option value="">— Select Client / User —</option>' +
          users.map(u => `<option value="${u.id}">${u.name} (${u.email})</option>`).join('');
      })
      .catch(() => {});
  }

  // Called when the Documents nav item is clicked
  function onDocumentsSectionShow() {
    loadAdminUploadUserList();
    loadDocuments();
  }

  async function adminUploadDocument() {
    const userId   = document.getElementById('admin_upload_user_id')?.value;
    const lotId    = document.getElementById('admin_upload_lot_id')?.value;
    const docType  = document.getElementById('admin_upload_doc_type')?.value;
    const fileInput = document.getElementById('admin_upload_file');
    const msgEl = document.getElementById('admin-upload-msg');

    if (!userId || !docType || !fileInput?.files?.length) {
      if (msgEl) { msgEl.style.display='block'; msgEl.style.color='#c0392b'; msgEl.textContent = 'Please select a user, document type, and file.'; }
      return;
    }

    const formData = new FormData();
    formData.append('action', 'admin_upload_document');
    formData.append('user_id', userId);
    if (lotId) formData.append('lot_id', lotId);
    formData.append('doc_type', docType);
    formData.append('admin_doc_file', fileInput.files[0]);

    if (msgEl) { msgEl.style.display='block'; msgEl.style.color='#555'; msgEl.textContent = 'Uploading…'; }

    try {
      const res = await fetch(window.location.pathname, { method: 'POST', body: formData });
      const data = await res.json();
      if (data.success) {
        if (msgEl) { msgEl.style.color='#27ae60'; msgEl.textContent = 'Document uploaded successfully and is now visible to the user.'; }
        document.getElementById('admin_upload_user_id').value = '';
        document.getElementById('admin_upload_lot_id').innerHTML = '<option value="">Select Lot (leave blank for all lots)</option>';
        document.getElementById('admin_upload_doc_type').value = '';
        fileInput.value = '';
        loadDocuments();
        refreshBadges();
      } else {
        if (msgEl) { msgEl.style.color='#c0392b'; msgEl.textContent = 'Upload failed: ' + (data.error || 'Unknown error'); }
      }
    } catch(e) {
      if (msgEl) { msgEl.style.color='#c0392b'; msgEl.textContent = 'Upload failed. Please try again.'; }
    }
  }

  async function onUserChangeForUpload() {
    const userId = document.getElementById('admin_upload_user_id')?.value;
    const lotSelect = document.getElementById('admin_upload_lot_id');
    if (!lotSelect) return;

    lotSelect.innerHTML = '<option value="">Loading lots...</option>';

    if (!userId) {
      lotSelect.innerHTML = '<option value="">Select Lot (leave blank for all lots)</option>';
      return;
    }

    try {
      const res = await fetch(`${window.location.pathname}?action=get_user_lots&user_id=${userId}&_ts=${Date.now()}`);
      const data = await res.json();
      if (data.success && data.lots) {
        let options = '<option value="">Select Lot (leave blank for all lots)</option>';
        data.lots.forEach(lot => {
          const blockText = (lot.block_number !== null && lot.block_number !== undefined && String(lot.block_number).trim() !== '')
            ? `Block ${lot.block_number}`
            : 'Block N/A';
          const lotText = (lot.lot_number !== null && lot.lot_number !== undefined && String(lot.lot_number).trim() !== '')
            ? `Lot ${lot.lot_number}`
            : 'Lot N/A';
          const locationText = (lot.location_name !== null && lot.location_name !== undefined && String(lot.location_name).trim() !== '')
            ? String(lot.location_name).trim()
            : 'Unknown Location';
          const label = `${blockText}, ${lotText} — ${locationText}`;
          options += `<option value="${lot.id}">${label}</option>`;
        });
        lotSelect.innerHTML = options;
      } else if (data.success) {
        lotSelect.innerHTML = '<option value="">No lots found</option>';
      } else {
        lotSelect.innerHTML = '<option value="">Error: ' + (data.error || 'Unknown error') + '</option>';
      }
    } catch(e) {
      lotSelect.innerHTML = '<option value="">Error loading lots: ' + e.message + '</option>';
    }
  }

  function loadDocuments() {
    const container = document.getElementById('documents-container');
    if (!container) return;

    container.innerHTML = '<p style="text-align: center; color: #666;">Loading documents...</p>';

    const params = new URLSearchParams();
    params.append('fetch', 'documents');

    const status = document.getElementById('docs_status')?.value || '';
    const docType = document.getElementById('docs_type')?.value?.trim() || '';
    const dateFrom = document.getElementById('docs_date_from')?.value || '';
    const dateTo = document.getElementById('docs_date_to')?.value || '';
    const search = document.getElementById('docs_search')?.value?.trim() || '';

    if (status) params.append('status', status);
    if (docType) params.append('doc_type', docType);
    if (dateFrom) params.append('date_from', dateFrom);
    if (dateTo) params.append('date_to', dateTo);
    if (search) params.append('search', search);

    fetch(window.location.pathname + '?' + params.toString())
      .then(response => response.json())
      .then(documents => {
        if (!documents.length) {
          container.innerHTML = '<p style="text-align: center; color: #666;">No pending documents found.</p>';
          return;
        }

        container.innerHTML = documents.map(doc => {
          const docId = doc.id ?? doc.doc_id ?? doc.document_id;
          const source = doc.source || 'user_documents';
          const fileName = doc.file_name || doc.filename || 'Untitled Document';
          const docTypeLabel = doc.doc_type || doc.type || 'N/A';
          const actionButtons = docId
            ? `<button class="btn btn-sm btn-primary" onclick="approveDocument(${docId}, '${source}')">Approve</button>
               <button class="btn btn-sm btn-danger"  onclick="rejectDocument(${docId}, '${source}')" style="background:#dc3545; color:#fff; border:1px solid #dc3545;">Reject</button>`
            : '';

          return `
            <div data-doc-id="${docId ?? ''}" style="padding: 12px; margin-bottom: 10px; border-radius: 6px; background: #fff; border: 1px solid #e0e0e0;">
              <strong>${fileName}</strong>
              <div style="font-size: 13px; color: #333;">Type: ${docTypeLabel}</div>
              <div style="font-size: 13px; color: #333;">User: ${doc.first_name || ''} ${doc.last_name || ''} (${doc.email || ''})</div>
              <div style="font-size: 12px; color: #999;">
                Uploaded: ${doc.uploaded_at ? new Date(doc.uploaded_at).toLocaleString() : 'N/A'}
              </div>
              <div style="font-size: 12px; color: #999;">
                Status: <span data-doc-status style="font-weight:600;">${doc.status || 'Pending'}</span>
              </div>
              <div data-doc-actions style="margin-top: 8px;">
                ${doc.file_path ? `<a href="${doc.file_path}" target="_blank" class="btn btn-sm btn-secondary">View</a>` : ''}
                ${actionButtons}
              </div>
            </div>
          `;
        }).join('');
      })
      .catch(error => {
        container.innerHTML = '<p style="text-align: center; color: #dc3545;">Failed to load user documents.</p>';
        console.error('Error loading documents:', error);
      });
  }

  function applyDocumentFilters() {
    loadDocuments();
  }

  function resetDocumentFilters() {
    const ids = ['docs_status', 'docs_type', 'docs_date_from', 'docs_date_to', 'docs_search'];
    ids.forEach(id => {
      const el = document.getElementById(id);
      if (el) el.value = '';
    });
    loadDocuments();
  }

  async function approveDocument(id, source = 'user_documents') {
    const proceed = await showConfirmModal('Approve this document?');
    if (!proceed) return;

    const formData = new FormData();
    formData.append('action', 'approve_document');
    formData.append('doc_id', id);
    formData.append('doc_source', source);

    fetch(window.location.pathname, { method: 'POST', body: formData })
      .then(r => r.json())
      .then(res => {
        if (res.success) {
          showMessage('Document approved.', true);
          loadDocuments();
          refreshBadges();
        } else {
          alert('Failed to approve document: ' + (res.error || 'Unknown error'));
        }
      })
      .catch(() => alert('Failed to approve document.'));
  }

  async function rejectDocument(id, source = 'user_documents') {
    const remarks = await showPromptModal('Enter remarks for rejection (optional):', '');
    if (remarks === null) return;

    const formData = new FormData();
    formData.append('action', 'reject_document');
    formData.append('doc_id', id);
    formData.append('remarks', remarks);
    formData.append('doc_source', source);

    fetch(window.location.pathname, { method: 'POST', body: formData })
      .then(r => r.json())
      .then(res => {
        if (res.success) {
          showMessage('Document rejected.', true);
          loadDocuments();
          refreshBadges();
        } else {
          alert('Failed to reject document: ' + (res.error || 'Unknown error'));
        }
      })
      .catch(() => alert('Failed to reject document.'));
  }

  function updateDocumentCardStatus(id, statusText) {
    const row = document.querySelector(`[data-doc-id="${id}"]`);
    if (!row) return;

    const statusEl = row.querySelector('[data-doc-status]');
    if (statusEl) {
      statusEl.textContent = statusText;
    }

    const actions = row.querySelector('[data-doc-actions]');
    if (actions) {
      actions.innerHTML = '<span style="color:#2d482d;font-weight:600;">✓ ' + statusText + '</span>';
    }
  }

  // ===========================
  // NOTIFICATIONS FUNCTIONS
  // ===========================
  function loadNotifications() {
    const container = document.getElementById('notifications-container');
    if (!container) return;

    container.innerHTML = '<p style="text-align: center; color: #666;">Loading notifications...</p>';

    const params = new URLSearchParams();
    params.append('fetch', 'notifications');

    const type = document.getElementById('notif_type')?.value || '';
    const dateFrom = document.getElementById('notif_date_from')?.value || '';
    const dateTo = document.getElementById('notif_date_to')?.value || '';
    const search = document.getElementById('notif_search')?.value?.trim() || '';

    if (type) params.append('type', type);
    if (dateFrom) params.append('date_from', dateFrom);
    if (dateTo) params.append('date_to', dateTo);
    if (search) params.append('search', search);

    fetch(window.location.pathname + '?' + params.toString())
      .then(response => response.json())
      .then(notifications => {
        if (!notifications.length) {
          container.innerHTML = '<p style="text-align: center; color: #666;">No notifications available.</p>';
          return;
        }

        container.innerHTML = notifications.map(notification => `
          <div class="notification-item" data-notification-type="${notification.type}">
            <strong class="notification-item-title">${notification.title}</strong>
            <p class="notification-item-message">${notification.message}</p>
            <small class="notification-item-time">${notification.created_at ? new Date(notification.created_at).toLocaleString() : ''}</small>
          </div>
        `).join('');

        container.querySelectorAll('[data-notification-type]').forEach(item => {
          const notifType = item.getAttribute('data-notification-type') || '';
          item.style.background = getNotificationColor(notifType);
          item.style.color = getNotificationTextColor(notifType);
        });
      })
      .catch(error => {
        container.innerHTML = '<p style="text-align: center; color: #dc3545;">Failed to load notifications.</p>';
        console.error('Error loading notifications:', error);
      });
  }

  function applyNotificationFilters() {
    loadNotifications();
  }

  function resetNotificationFilters() {
    const ids = ['notif_type', 'notif_date_from', 'notif_date_to', 'notif_search'];
    ids.forEach(id => {
      const el = document.getElementById(id);
      if (el) el.value = '';
    });
    loadNotifications();
  }

  function getNotificationColor(type) {
    switch (type) {
      case 'success': return '#d4edda';
      case 'warning': return '#fff3cd';
      case 'error':   return '#f8d7da';
      default:        return '#e2e3e5';
    }
  }

  function getNotificationTextColor(type) {
    switch (type) {
      case 'success': return '#155724';
      case 'warning': return '#856404';
      case 'error':   return '#721c24';
      default:        return '#383d41';
    }
  }

  // ===========================
  // AUDIT LOGS FUNCTIONS
  // ===========================
  function loadAuditLogs() {
    const container = document.getElementById('audit-logs-container');
    if (!container) return;

    container.innerHTML = '<p style="text-align: center; color: #666;">Loading audit logs...</p>';

    const params = new URLSearchParams();
    params.append('fetch', 'audit_logs');

    const action = document.getElementById('audit_action')?.value?.trim() || '';
    const dateFrom = document.getElementById('audit_date_from')?.value || '';
    const dateTo = document.getElementById('audit_date_to')?.value || '';
    const search = document.getElementById('audit_search')?.value?.trim() || '';

    if (action) params.append('action', action);
    if (dateFrom) params.append('date_from', dateFrom);
    if (dateTo) params.append('date_to', dateTo);
    if (search) params.append('search', search);

    fetch(window.location.pathname + '?' + params.toString())
      .then(response => response.json())
      .then(logs => {
        if (!logs.length) {
          container.innerHTML = '<p style="text-align: center; color: #666;">No audit logs found.</p>';
          return;
        }

        container.innerHTML = logs.map(log => `
          <div style="padding: 12px; margin-bottom: 10px; border-radius: 6px; background: #fff; border: 1px solid #e0e0e0;">
            <strong>${log.action}</strong>
            <div style="font-size: 13px; color: #333;">${log.details}</div>
            <div style="font-size: 12px; color: #999;">
              By: ${log.first_name || ''} ${log.last_name || ''} • ${log.created_at ? new Date(log.created_at).toLocaleDateString() + ' ' + new Date(log.created_at).toLocaleTimeString() : ''}
            </div>
          </div>
        `).join('');
      })
      .catch(error => {
        container.innerHTML = '<p style="text-align: center; color: #dc3545;">Failed to load audit logs.</p>';
        console.error('Error loading audit logs:', error);
      });
  }

  function applyAuditFilters() {
    loadAuditLogs();
  }

  function resetAuditFilters() {
    const ids = ['audit_action', 'audit_date_from', 'audit_date_to', 'audit_search'];
    ids.forEach(id => {
      const el = document.getElementById(id);
      if (el) el.value = '';
    });
    loadAuditLogs();
  }

  // ===========================
  // PAYMENTS FUNCTIONS
  // ===========================
  function loadPayments() {
    const tbody = document.getElementById('payments-tbody');
    if (!tbody) return;
    const paymentSearchInput = document.getElementById('paymentSearchInput');
    const paymentSearchValue = (paymentSearchInput?.value || '').trim().toLowerCase();

    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:30px; color:#6c757d;">Loading payments...</td></tr>';

    fetch(window.location.pathname + '?fetch=all_payments', { method: 'GET' })
      .then(response => response.json())
      .then(data => {
        if (!data || !data.payments || data.payments.length === 0) {
          tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; padding: 30px; color: #6c757d;">No payments found.</td></tr>';
          return;
        }

        const filteredPayments = (data.payments || []).filter(payment => {
          if (!paymentSearchValue) return true;
          const haystack = [
            payment.owner_name,
            payment.email,
            payment.mobile_number,
            payment.location_name,
            payment.block_number,
            payment.lot_number
          ].map(v => String(v || '').toLowerCase()).join(' ');
          return haystack.includes(paymentSearchValue);
        });

        if (!filteredPayments.length) {
          tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; padding: 30px; color: #6c757d;">No matching payment records found.</td></tr>';
          return;
        }

        tbody.innerHTML = filteredPayments.map(payment => {
          const autoStage = getAutoWorkflowStage(payment);
          const paymentTypeLabel = payment.payment_type || 'Not Applicable';
          const paymentTypeClass = getPaymentTypeClass(paymentTypeLabel);
          const hasBalance = parseFloat(payment.balance_due || 0) > 0;
          const installmentAmount = Number(payment.payment_amount || 0);
          const downPaymentAmount = Number(payment.down_payment_amount || 0);
          const installmentYears = Number(payment.payment_term_years || 0);
          const dueDayValue = Number(payment.payment_due_day || 0);
          const hasDeadline = !!payment.payment_deadline;
          return `
          <tr>
            <td>${payment.block_number || 'N/A'}</td>
            <td>${payment.lot_number || 'N/A'}</td>
            <td class="col-location">${payment.location_name || 'N/A'}</td>
            <td class="col-owner">${payment.owner_name || '<span class=\"payment-owner-empty\">Unassigned</span>'}</td>
            <td><span class="payment-chip ${paymentTypeClass}">${paymentTypeLabel}</span></td>
            <td class="amount-paid">₱${parseFloat(payment.total_paid || 0).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
            <td class="amount-balance ${hasBalance ? 'amount-balance-due' : 'amount-balance-clear'}">₱${parseFloat(payment.balance_due || 0).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
            <td class="amount-price">₱${payment.lot_price ? parseFloat(payment.lot_price).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '0.00'}</td>
            <td class="col-actions" style="text-align:center;">
              <div class="payment-actions payment-actions-image-ref">
                <button type="button" class="payment-action-btn${(autoStage === 'Not Applicable') ? ' payment-action-disabled' : ''}" ${(autoStage === 'Not Applicable') ? 'disabled' : ''} title="Set Installment" onclick="${(autoStage !== 'Not Applicable') ? `openInstallmentPlanModal(${payment.lot_id}, ${Number(payment.lot_price || 0)}, ${installmentAmount || 0}, ${downPaymentAmount || 0}, ${installmentYears || 0}, ${dueDayValue || 0}, '${hasDeadline ? escapeText(payment.payment_deadline) : ''}', true)` : ''}">Set Installment</button>
                <button type="button" class="payment-action-btn${(autoStage === 'Paid') ? ' payment-action-disabled' : ''}" ${(autoStage === 'Paid') ? 'disabled' : ''} title="Record Payment" onclick="${(autoStage !== 'Paid') ? `recordPaymentForLot(${payment.lot_id}, ${installmentAmount || 0}, ${downPaymentAmount || 0}, ${Number(payment.balance_due || 0)}, ${Number(payment.total_paid || 0)}, ${Number(payment.lot_price || 0)}, ${dueDayValue || 0}, '${hasDeadline ? escapeText(payment.payment_deadline) : ''}', '${payment.last_payment_date ? escapeText(payment.last_payment_date) : ''}', ${installmentYears || 0})` : ''}">Record Payment</button>
                <button type="button" class="payment-action-btn${(autoStage === 'Not Applicable') ? ' payment-action-disabled' : ''}" ${(autoStage === 'Not Applicable') ? 'disabled' : ''} title="View Payment History" onclick="${(autoStage !== 'Not Applicable') ? `showPaymentHistory(${payment.lot_id})` : ''}">View History</button>
                <button type="button" class="payment-action-btn${(autoStage !== 'Paid') ? ' payment-action-disabled' : ''}" ${(autoStage !== 'Paid') ? 'disabled' : ''} title="Turnover Title" onclick="${(autoStage === 'Paid') ? `markTurnoverForLot(${payment.lot_id}, true)` : ''}">Turnover Title</button>
              </div>
            </td>
          </tr>
        `;
        }).join('');
      })
      .catch(error => {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; padding: 30px; color: #dc3545;">Failed to load payments.</td></tr>';
        console.error('Error loading payments:', error);
      });
  }

  function getPaymentTypeClass(type) {
    switch(String(type || '').trim()) {
      case 'Down Payment': return 'payment-chip-dp';
      case 'Cash': return 'payment-chip-cash';
      case 'Fully Paid': return 'payment-chip-paid';
      default: return 'payment-chip-na';
    }
  }

  function getAutoWorkflowStage(payment) {
    const status = String(payment?.status || '').trim();
    const paymentType = String(payment?.payment_type || '').trim();
    const totalPaid = Number(payment?.total_paid || 0);
    const lotPrice = Number(payment?.lot_price || 0);

    if (status === 'Available') return 'Available';
    if ((lotPrice > 0 && totalPaid >= lotPrice) || status === 'Paid' || paymentType === 'Fully Paid') return 'Paid';
    if (paymentType === 'Down Payment' || totalPaid > 0) return 'Installments';
    if (status === 'Reserved') return 'Reserved';
    return 'Available';
  }

  function getWorkflowBadgeClass(stage) {
    switch (stage) {
      case 'Reserved': return 'workflow-reservation';
      case 'Installments': return 'workflow-installments';
      case 'Paid': return 'workflow-paid';
      case 'Available':
      default:
        return 'workflow-available';
    }
  }

  function escapeText(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function showModalById(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'flex';
  }

  function hideModalById(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
  }

  function calcNextDueDate(dueDay, paymentDeadline, lastPaymentDate) {
    if (!dueDay || dueDay < 1 || dueDay > 31) {
      return new Date().toISOString().slice(0, 10);
    }

    if (lastPaymentDate) {
      var lastDate = new Date(lastPaymentDate + 'T00:00:00');
      if (!Number.isNaN(lastDate.getTime())) {
        var ly = lastDate.getFullYear();
        var lm = lastDate.getMonth() + 1;
        if (lm > 11) {
          lm = 0;
          ly++;
        }

        var ldim = new Date(ly, lm + 1, 0).getDate();
        var next = new Date(ly, lm, Math.min(dueDay, ldim));
        return next.getFullYear() + '-' + String(next.getMonth() + 1).padStart(2, '0') + '-' + String(next.getDate()).padStart(2, '0');
      }
    }

    if (paymentDeadline) {
      var parts = String(paymentDeadline).split('-').map(Number);
      if (parts.length === 3 && !parts.some(Number.isNaN)) {
        return parts[0] + '-' + String(parts[1]).padStart(2, '0') + '-' + String(Math.min(parts[2], 31)).padStart(2, '0');
      }
    }

    var today = new Date();
    today.setHours(0, 0, 0, 0);
    var y = today.getFullYear();
    var m = today.getMonth();
    var dim = new Date(y, m + 1, 0).getDate();
    var candidate = new Date(y, m, Math.min(dueDay, dim));
    if (candidate < today) {
      m++;
      if (m > 11) { m = 0; y++; }
      dim = new Date(y, m + 1, 0).getDate();
      candidate = new Date(y, m, Math.min(dueDay, dim));
    }
    return y + '-' + String(m + 1).padStart(2, '0') + '-' + String(candidate.getDate()).padStart(2, '0');
  }

  function calculateInstallmentDueAmount(lotPrice, monthlyAmount, downPaymentAmount, totalPaid) {
    const cleanLotPrice = Math.max(0, Number(lotPrice || 0));
    const cleanMonthlyAmount = Math.max(0, Number(monthlyAmount || 0));
    const cleanDownPaymentAmount = Math.max(0, Number(downPaymentAmount || 0));
    const cleanTotalPaid = Math.max(0, Number(totalPaid || 0));

    const installmentBalance = Math.max(cleanLotPrice - cleanDownPaymentAmount, 0);
    const installmentPaid = Math.max(0, cleanTotalPaid - cleanDownPaymentAmount);
    const remainingBalance = Math.max(0, installmentBalance - installmentPaid);

    if (remainingBalance <= 0) {
      return 0;
    }

    const baseDue = cleanMonthlyAmount > 0 ? Math.min(cleanMonthlyAmount, remainingBalance) : remainingBalance;
    if (cleanMonthlyAmount <= 0) {
      return baseDue;
    }

    const currentCyclePaid = installmentPaid % cleanMonthlyAmount;
    return Math.max(0, baseDue - currentCyclePaid);
  }

  let recordPaymentSelectionContext = null;

  function buildInstallmentAnchorDate(paymentDeadline, lastPaymentDate, dueDay) {
    const safeDueDay = Math.max(1, Math.min(31, Number(dueDay || 1)));
    const parseIso = (raw) => {
      const m = String(raw || '').trim().match(/^(\d{4})-(\d{2})-(\d{2})$/);
      if (!m) return null;
      const y = Number(m[1]);
      const mm = Number(m[2]) - 1;
      const dd = Number(m[3]);
      const d = new Date(y, mm, dd);
      if (Number.isNaN(d.getTime())) return null;
      return d;
    };

    const deadlineDate = parseIso(paymentDeadline);
    if (deadlineDate) {
      return new Date(deadlineDate.getFullYear(), deadlineDate.getMonth(), Math.min(safeDueDay, new Date(deadlineDate.getFullYear(), deadlineDate.getMonth() + 1, 0).getDate()));
    }

    const lastDate = parseIso(lastPaymentDate);
    if (lastDate) {
      return new Date(lastDate.getFullYear(), lastDate.getMonth(), Math.min(safeDueDay, new Date(lastDate.getFullYear(), lastDate.getMonth() + 1, 0).getDate()));
    }

    const today = new Date();
    return new Date(today.getFullYear(), today.getMonth(), Math.min(safeDueDay, new Date(today.getFullYear(), today.getMonth() + 1, 0).getDate()));
  }

  function buildRecordPaymentMonthOptions(lotPrice, monthlyAmount, downPaymentAmount, totalPaid, termYears, dueDay, paymentDeadline, lastPaymentDate) {
    const safeLotPrice = Math.max(0, Number(lotPrice || 0));
    const safeMonthly = Math.max(0, Number(monthlyAmount || 0));
    const safeDownPayment = Math.max(0, Number(downPaymentAmount || 0));
    const safeTotalPaid = Math.max(0, Number(totalPaid || 0));
    const safeTermYears = Math.max(0, Number(termYears || 0));
    const totalMonths = safeTermYears > 0 ? Math.round(safeTermYears * 12) : 0;

    if (totalMonths <= 0 || safeMonthly <= 0) {
      return [];
    }

    const contractBalance = Math.max(0, safeLotPrice - safeDownPayment);
    if (contractBalance <= 0) {
      return [];
    }

    let adjustedTotalPaid = safeTotalPaid;
    if (safeDownPayment > 0 && safeTotalPaid < safeDownPayment) {
      adjustedTotalPaid = safeTotalPaid + safeDownPayment;
    }

    const monthlyPaidTotal = Math.max(0, adjustedTotalPaid - safeDownPayment);
    const paidMonthsCount = Math.min(totalMonths, Math.floor((monthlyPaidTotal + 0.0001) / safeMonthly));
    const anchor = buildInstallmentAnchorDate(paymentDeadline, lastPaymentDate, dueDay);

    const options = [];
    let remainingByPlan = contractBalance;
    for (let i = 0; i < totalMonths; i++) {
      const dueDate = new Date(anchor.getFullYear(), anchor.getMonth() + i, 1);
      const daysInMonth = new Date(dueDate.getFullYear(), dueDate.getMonth() + 1, 0).getDate();
      dueDate.setDate(Math.min(Math.max(1, Number(dueDay || 1)), daysInMonth));

      const monthsLeft = totalMonths - i;
      let plannedAmount = monthsLeft <= 1 ? remainingByPlan : Math.min(safeMonthly, remainingByPlan);
      plannedAmount = Math.max(0, Number(plannedAmount.toFixed(2)));
      remainingByPlan = Math.max(0, Number((remainingByPlan - plannedAmount).toFixed(2)));

      if (i >= paidMonthsCount && plannedAmount > 0) {
        options.push({
          monthIndex: i + 1,
          dueDate,
          amount: plannedAmount,
          selected: false,
        });
      }
    }

    return options;
  }

  function renderRecordPaymentMonthOptions() {
    const listEl = document.getElementById('record-payment-month-list');
    const summaryEl = document.getElementById('record-payment-month-summary');
    if (!listEl || !summaryEl) return;

    const ctx = recordPaymentSelectionContext;
    if (!ctx || !Array.isArray(ctx.monthOptions) || !ctx.monthOptions.length) {
      listEl.innerHTML = '<div style="padding:6px; color:#6c757d; font-size:12px;">No unpaid installment months available for selection.</div>';
      summaryEl.textContent = 'No unpaid installment months found.';
      return;
    }

    listEl.innerHTML = ctx.monthOptions.map((opt, idx) => {
      const monthLabel = opt.dueDate.toLocaleDateString('en-PH', { month: 'long', year: 'numeric' });
      const dueLabel = opt.dueDate.toLocaleDateString('en-PH', { day: '2-digit', month: 'short', year: 'numeric' });
      return `
        <label style="display:flex; align-items:flex-start; gap:8px; padding:8px 6px; border-bottom:1px solid #f1f3f5; cursor:pointer;">
          <input type="checkbox" data-month-option-index="${idx}" onchange="toggleRecordPaymentMonth(${idx})" ${opt.selected ? 'checked' : ''}>
          <span style="font-size:13px; color:#2f3a33;">
            <strong>${monthLabel}</strong><br>
            <span style="color:#6c757d; font-size:12px;">Due ${dueLabel} • ${formatPhpAmount(opt.amount)}</span>
          </span>
        </label>
      `;
    }).join('');

    updateRecordPaymentMonthSummary();
  }

  function toggleRecordPaymentMonth(index) {
    if (!recordPaymentSelectionContext || !Array.isArray(recordPaymentSelectionContext.monthOptions)) return;
    const i = Number(index);
    if (!Number.isInteger(i) || i < 0 || i >= recordPaymentSelectionContext.monthOptions.length) return;
    const opt = recordPaymentSelectionContext.monthOptions[i];
    opt.selected = !opt.selected;
    updateRecordPaymentMonthSummary();
  }

  function updateRecordPaymentMonthSummary() {
    const summaryEl = document.getElementById('record-payment-month-summary');
    if (!summaryEl) return;

    if (!recordPaymentSelectionContext || !Array.isArray(recordPaymentSelectionContext.monthOptions)) {
      summaryEl.textContent = 'Select one or more unpaid months to proceed.';
      return;
    }

    const selected = recordPaymentSelectionContext.monthOptions.filter(opt => !!opt.selected);
    const total = selected.reduce((sum, opt) => sum + Number(opt.amount || 0), 0);
    if (!selected.length) {
      summaryEl.textContent = 'Select one or more unpaid months to proceed.';
      return;
    }

    summaryEl.textContent = `${selected.length} month(s) selected • Total ${formatPhpAmount(total)}`;
  }

  function proceedRecordPaymentDetails() {
    const detailsEl = document.getElementById('record-payment-details-section');
    const amountInput = document.getElementById('record-payment-amount');
    const dateInput = document.getElementById('record-payment-date');
    const methodInput = document.getElementById('record-payment-method');
    const remarksInput = document.getElementById('record-payment-remarks');

    if (!detailsEl || !amountInput || !dateInput || !methodInput || !remarksInput) {
      alert('Payment form is not available.');
      return;
    }

    const selected = (recordPaymentSelectionContext?.monthOptions || []).filter(opt => !!opt.selected);
    if (!selected.length) {
      alert('Please select at least one unpaid month before proceeding.');
      return;
    }

    const total = selected.reduce((sum, opt) => sum + Number(opt.amount || 0), 0);
    amountInput.value = total > 0 ? total.toFixed(2) : '';
    const firstSelectedMonth = selected[0] || null;
    if (firstSelectedMonth && firstSelectedMonth.dueDate instanceof Date) {
      const year = firstSelectedMonth.dueDate.getFullYear();
      const month = String(firstSelectedMonth.dueDate.getMonth() + 1).padStart(2, '0');
      const day = String(firstSelectedMonth.dueDate.getDate()).padStart(2, '0');
      dateInput.value = `${year}-${month}-${day}`;
    } else {
      dateInput.value = new Date().toISOString().slice(0, 10);
    }
    methodInput.value = 'Cash';

    const monthNames = selected.map(opt => opt.dueDate.toLocaleDateString('en-PH', { month: 'long', year: 'numeric' }));
    const monthRemark = `Installment months paid: ${monthNames.join(', ')}`;
    remarksInput.value = monthRemark;

    detailsEl.style.display = 'block';
    amountInput.focus();
    amountInput.select();
  }

  function resetRecordPaymentModalState() {
    const detailsEl = document.getElementById('record-payment-details-section');
    const listEl = document.getElementById('record-payment-month-list');
    const summaryEl = document.getElementById('record-payment-month-summary');
    const amountInput = document.getElementById('record-payment-amount');
    const dateInput = document.getElementById('record-payment-date');
    const methodInput = document.getElementById('record-payment-method');
    const remarksInput = document.getElementById('record-payment-remarks');

    recordPaymentSelectionContext = null;
    if (detailsEl) detailsEl.style.display = 'none';
    if (listEl) listEl.innerHTML = '';
    if (summaryEl) summaryEl.textContent = 'Select one or more unpaid months to proceed.';
    if (amountInput) amountInput.value = '';
    if (dateInput) dateInput.value = '';
    if (methodInput) methodInput.value = 'Cash';
    if (remarksInput) remarksInput.value = '';
  }

  function recordPaymentForLot(lotId, suggestedAmount, downPaymentAmount, balanceDue, totalPaid, lotPrice, dueDay, paymentDeadline, lastPaymentDate, termYears) {
    const lotInput = document.getElementById('record-payment-lot-id');
    const monthListEl = document.getElementById('record-payment-month-list');
    const summaryEl = document.getElementById('record-payment-month-summary');

    if (!lotInput || !monthListEl || !summaryEl) {
      alert('Payment form is not available.');
      return;
    }

    resetRecordPaymentModalState();
    lotInput.value = String(lotId);

    const options = buildRecordPaymentMonthOptions(
      Number(lotPrice || 0),
      Number(suggestedAmount || 0),
      Number(downPaymentAmount || 0),
      Number(totalPaid || 0),
      Number(termYears || 0),
      Number(dueDay || 0),
      paymentDeadline || '',
      lastPaymentDate || ''
    );

    if (!options.length) {
      const fallbackAmount = Math.max(0, Number(suggestedAmount || 0));
      const dueDate = calcNextDueDate(Number(dueDay) || 0, paymentDeadline || '', lastPaymentDate || '');
      const m = String(dueDate || '').trim().match(/^(\d{4})-(\d{2})-(\d{2})$/);
      const fallbackDate = m
        ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]))
        : new Date();
      recordPaymentSelectionContext = {
        monthOptions: fallbackAmount > 0 ? [{ monthIndex: 1, dueDate: fallbackDate, amount: fallbackAmount, selected: false }] : []
      };
      if (recordPaymentSelectionContext.monthOptions.length) {
        renderRecordPaymentMonthOptions();
      } else {
        monthListEl.innerHTML = '<div style="padding:6px; color:#6c757d; font-size:12px;">No unpaid installment months found.</div>';
        summaryEl.textContent = 'No unpaid installment months found.';
      }
    } else {
      recordPaymentSelectionContext = { monthOptions: options };
      renderRecordPaymentMonthOptions();
    }

    showModalById('recordPaymentModal');
  }

  function closeRecordPaymentModal() {
    resetRecordPaymentModalState();
    hideModalById('recordPaymentModal');
  }

  function openInstallmentPlanModal(lotId, lotPrice, amount, downPaymentAmount, termYears, dueDay, deadline, canEdit) {
    if (!canEdit) {
      alert('Installment plan cannot be changed for fully paid lots.');
      return;
    }

    const lotInput = document.getElementById('installment-lot-id');
    const lotPriceInput = document.getElementById('installment-lot-price');
    const lotPriceDisplay = document.getElementById('installment-lot-price-display');
    const amountInput = document.getElementById('installment-amount');
    const downPaymentInput = document.getElementById('down-payment-amount');
    const termYearsInput = document.getElementById('installment-term-years');
    const dueDayInput = document.getElementById('installment-due-day');
    const remainingBalanceInput = document.getElementById('installment-remaining-balance');
    const deadlineInput = document.getElementById('installment-deadline');

    if (!lotInput || !lotPriceInput || !lotPriceDisplay || !amountInput || !downPaymentInput || !termYearsInput || !dueDayInput || !remainingBalanceInput || !deadlineInput) {
      alert('Installment form is not available.');
      return;
    }

    lotInput.value = String(lotId || 0);
    lotPriceInput.value = String(Number(lotPrice || 0));
    lotPriceDisplay.value = formatPhpAmount(Number(lotPrice || 0));
    amountInput.value = Number(amount || 0) > 0 ? formatPhpAmount(Number(amount || 0)) : '';
    downPaymentInput.value = Number(downPaymentAmount || 0) > 0 ? Number(downPaymentAmount).toFixed(2) : '';
    termYearsInput.value = Number(termYears || 0) > 0 ? String(termYears) : '';
    dueDayInput.value = Number(dueDay || 0) > 0 ? String(dueDay) : '';
    remainingBalanceInput.value = '';
    deadlineInput.value = (deadline || '').trim();
    recalculateInstallmentPlan();
    showModalById('installmentPlanModal');
  }

  function closeInstallmentPlanModal() {
    hideModalById('installmentPlanModal');
  }

  function formatPhpAmount(value) {
    const amount = Number(value || 0);
    return 'PHP ' + amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function enforceAdminTermYearsRange(inputEl) {
    if (!inputEl) return;
    const raw = String(inputEl.value || '').trim();
    if (raw === '') {
      inputEl.dataset.termWarned = '';
      return;
    }

    const years = Number(raw);
    if (!Number.isFinite(years)) return;

    if (years < 1 || years > 5) {
      const clamped = years < 1 ? 1 : 5;
      inputEl.value = String(clamped);
      if (inputEl.dataset.termWarned !== '1') {
        alert('Payment term is only 1 to 5 years.');
        inputEl.dataset.termWarned = '1';
      }
      return;
    }

    inputEl.dataset.termWarned = '';
  }

  function recalculateInstallmentPlan() {
    const lotPrice = Number(document.getElementById('installment-lot-price')?.value || 0);
    const downPaymentInput = document.getElementById('down-payment-amount');
    const termYearsInput = document.getElementById('installment-term-years');
    const remainingBalanceInput = document.getElementById('installment-remaining-balance');
    const amountInput = document.getElementById('installment-amount');

    if (!downPaymentInput || !termYearsInput || !remainingBalanceInput || !amountInput) {
      return;
    }

    enforceAdminTermYearsRange(termYearsInput);

    const downPayment = Number(downPaymentInput.value || 0);
    const termYears = Number(termYearsInput.value || 0);
    const safeDownPayment = Math.max(0, downPayment);
    const remainingBalance = Math.max(0, lotPrice - safeDownPayment);

    remainingBalanceInput.value = formatPhpAmount(remainingBalance);

    if (termYears > 0 && remainingBalance > 0) {
      const monthlyAmount = remainingBalance / (termYears * 12);
      amountInput.value = formatPhpAmount(monthlyAmount);
    } else {
      amountInput.value = '';
    }
  }

  async function submitInstallmentPlan() {
    const lotId = Number(document.getElementById('installment-lot-id')?.value || 0);
    const lotPrice = Number(document.getElementById('installment-lot-price')?.value || 0);
    const downPaymentAmount = Number(document.getElementById('down-payment-amount')?.value || 0);
    const termYears = Number(document.getElementById('installment-term-years')?.value || 0);
    const dueDay = Number(document.getElementById('installment-due-day')?.value || 0);
    const deadline = (document.getElementById('installment-deadline')?.value || '').trim();

    if (!lotId) {
      alert('Missing lot reference.');
      return;
    }

    if (!Number.isFinite(termYears) || termYears < 1 || termYears > 5) {
      alert('Please enter payment term from 1 to 5 years only.');
      return;
    }

    if (!Number.isFinite(downPaymentAmount) || downPaymentAmount < 0) {
      alert('Please enter a valid down payment amount.');
      return;
    }

    if (downPaymentAmount >= lotPrice) {
      alert('Down payment must be less than the lot price.');
      return;
    }

    if (!Number.isFinite(dueDay) || dueDay < 1 || dueDay > 31) {
      alert('Please enter what day of the month the client should pay (1-31).');
      return;
    }

    if (!deadline) {
      const proceed = await showConfirmModal('No exact deadline entered. The system will auto-generate the next due date from the monthly due day. Continue?');
      if (!proceed) return;
    }

    const fd = new FormData();
    fd.append('action', 'update_installment_plan');
    fd.append('lot_id', String(lotId));
    if (Number.isFinite(downPaymentAmount) && downPaymentAmount > 0) {
      fd.append('down_payment_amount', String(downPaymentAmount));
    }
    fd.append('payment_term_years', String(termYears));
    fd.append('payment_due_day', String(dueDay));
    fd.append('payment_deadline', deadline);

    fetch(window.location.pathname, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(res => {
        if (!res.success) {
          alert('Failed to update installment: ' + (res.error || 'Unknown error'));
          return;
        }
        closeInstallmentPlanModal();
        showMessage('Installment plan updated successfully.', true);
        loadPayments();
        loadLotOwners();
      })
      .catch(err => {
        console.error(err);
        alert('Failed to update installment plan.');
      });
  }

  function submitRecordPayment() {
    const showPaymentNotice = (message, title = 'Notice') => {
      if (typeof showAlertModal === 'function') {
        showAlertModal(message, title);
      } else {
        alert(message);
      }
    };

    const lotId = Number(document.getElementById('record-payment-lot-id')?.value || 0);
    const amount = Number(document.getElementById('record-payment-amount')?.value || 0);
    const paymentDate = (document.getElementById('record-payment-date')?.value || '').trim();
    const method = (document.getElementById('record-payment-method')?.value || 'Cash').trim();
    const remarks = (document.getElementById('record-payment-remarks')?.value || '').trim();
    const detailsSection = document.getElementById('record-payment-details-section');
    const detailsVisible = !!detailsSection && getComputedStyle(detailsSection).display !== 'none';

    if (!detailsVisible) {
      showPaymentNotice('Please select unpaid month(s) and click Proceed first.', 'Month Selection Required');
      return;
    }

    if (!lotId || !Number.isFinite(amount) || amount <= 0) {
      showPaymentNotice('Please enter a valid payment amount.', 'Invalid Payment');
      return;
    }

    const selectedMonths = (recordPaymentSelectionContext?.monthOptions || [])
      .filter(opt => !!opt.selected)
      .map(opt => opt.dueDate.toLocaleDateString('en-PH', { month: 'long', year: 'numeric' }));

    if (!selectedMonths.length) {
      showPaymentNotice('Please select unpaid month(s) before saving.', 'Month Selection Required');
      return;
    }

    const finalRemarks = remarks || (`Installment months paid: ${selectedMonths.join(', ')}`);

    const fd = new FormData();
    fd.append('action', 'add_payment_transaction');
    fd.append('lot_id', String(lotId));
    fd.append('amount', String(amount));
    fd.append('payment_date', paymentDate);
    fd.append('payment_method', method || 'Cash');
    fd.append('remarks', finalRemarks);

    fetch(window.location.pathname, { method: 'POST', body: fd })
      .then(r => r.text())
      .then(text => {
        let res;
        try {
          res = JSON.parse(text);
        } catch (e) {
          const firstBrace = text.indexOf('{');
          const lastBrace = text.lastIndexOf('}');
          if (firstBrace !== -1 && lastBrace !== -1 && lastBrace > firstBrace) {
            res = JSON.parse(text.slice(firstBrace, lastBrace + 1));
          } else {
            throw e;
          }
        }

        if (!res.success) {
          showPaymentNotice('Failed to record payment: ' + (res.error || 'Unknown error'), 'Payment Failed');
          return;
        }
        closeRecordPaymentModal();
        showPaymentNotice('Payment recorded successfully.', 'Payment Successful');
        showMessage('Payment recorded successfully.', true);
        loadPayments();
        loadLotOwners();
        const locSel = document.getElementById('location_id');
        loadLots(locSel ? locSel.value : '');
      })
      .catch(err => {
        console.error(err);
        showPaymentNotice('Failed to record payment.', 'Payment Failed');
      });
  }

  function closePaymentHistoryModal() {
    hideModalById('paymentHistoryModal');
  }

  function showPaymentHistory(lotId) {
    console.log('showPaymentHistory called with lotId:', lotId);
    if (!lotId || isNaN(lotId)) {
      alert('Invalid lot ID for payment history.');
      return;
    }

    const content = document.getElementById('payment-history-content');
    if (!content) {
      alert('Payment history view is not available.');
      return;
    }

    content.innerHTML = '<div style="padding:16px; color:#6c757d; text-align:center;">Loading payment history...</div>';
    showModalById('paymentHistoryModal');

    Promise.allSettled([
      fetch(`${window.location.pathname}?fetch=payment_transactions&lot_id=${lotId}&_=${Date.now()}`)
        .then(r => r.json())
        .catch(err => ({ success: false, error: 'Network or parse error: ' + err.message })),
      fetch(`${window.location.pathname}?fetch=lot_history&lot_id=${lotId}&_=${Date.now()}`)
        .then(r => r.json())
        .catch(err => ({ success: true, history: [], error: 'History fetch failed: ' + err.message }))
    ])
      .then(([paymentsResult, historyResult]) => {
        console.log('Payments result:', paymentsResult);
        console.log('History result:', historyResult);
        if (paymentsResult.status !== 'fulfilled' || !paymentsResult.value.success) {
          const errorMsg = paymentsResult.value?.error || 'Failed to load payment data';
          content.innerHTML = `<div style="padding:16px; color:#dc3545; text-align:center;">${errorMsg}</div>`;
          return;
        }

        const paymentsData = paymentsResult.value;
        const historyData = (historyResult.status === 'fulfilled' ? historyResult.value : { success: true, history: [] });

        const getDateKey = (value) => {
          const raw = String(value || '').trim();
          if (!raw) return 0;
          const iso = raw.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
          if (iso) {
            const y = Number(iso[1]);
            const m = Number(iso[2]);
            const d = Number(iso[3]);
            return (y * 10000) + (m * 100) + d;
          }
          const parsed = new Date(raw);
          if (!Number.isNaN(parsed.getTime())) {
            return (parsed.getFullYear() * 10000) + ((parsed.getMonth() + 1) * 100) + parsed.getDate();
          }
          return 0;
        };

        const rows = (paymentsData.transactions || []).slice().sort((a, b) => {
          const ta = getDateKey(a.payment_date);
          const tb = getDateKey(b.payment_date);
          if (ta !== tb) return ta - tb;
          return Number(a.id || 0) - Number(b.id || 0);
        });

        const history = (historyData.success && Array.isArray(historyData.history)) ? historyData.history : [];

        const paymentSection = rows.length ? `
          <div style="margin-bottom:18px;">
            <div style="font-size:14px; font-weight:700; color:#111827; margin-bottom:10px;">Payment History</div>
            <table style="width:100%; border-collapse:collapse;">
              <thead>
                <tr style="background:#f8f9fa;">
                  <th style="padding:10px 12px; text-align:left; border-bottom:1px solid #dee2e6; font-size:12px; color:#495057;">Date</th>
                  <th style="padding:10px 12px; text-align:right; border-bottom:1px solid #dee2e6; font-size:12px; color:#495057;">Amount</th>
                  <th style="padding:10px 12px; text-align:left; border-bottom:1px solid #dee2e6; font-size:12px; color:#495057;">Method</th>
                  <th style="padding:10px 12px; text-align:left; border-bottom:1px solid #dee2e6; font-size:12px; color:#495057;">Paid By</th>
                  <th style="padding:10px 12px; text-align:left; border-bottom:1px solid #dee2e6; font-size:12px; color:#495057;">Remarks</th>
                </tr>
              </thead>
              <tbody>
                ${rows.map(tx => {
                  const amountText = Number(tx.amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                  return `
                    <tr>
                      <td style="padding:10px 12px; border-bottom:1px solid #f1f3f5; font-size:13px; color:#343a40;">${escapeText(tx.payment_date || 'N/A')}</td>
                      <td style="padding:10px 12px; border-bottom:1px solid #f1f3f5; font-size:13px; color:#111827; text-align:right; font-weight:600;">PHP ${amountText}</td>
                      <td style="padding:10px 12px; border-bottom:1px solid #f1f3f5; font-size:13px; color:#343a40;">${escapeText(tx.payment_method || 'N/A')}</td>
                      <td style="padding:10px 12px; border-bottom:1px solid #f1f3f5; font-size:13px; color:#343a40;">${escapeText(tx.paid_by || 'N/A')}</td>
                      <td style="padding:10px 12px; border-bottom:1px solid #f1f3f5; font-size:13px; color:#495057;">${escapeText(tx.remarks || '')}</td>
                    </tr>
                  `;
                }).join('')}
              </tbody>
            </table>
          </div>
        ` : '<div style="padding:16px; color:#6c757d; text-align:center;">No payment records for this lot yet.</div>';

        const historySection = history.length ? `
          <div>
            <div style="font-size:14px; font-weight:700; color:#111827; margin-bottom:10px;">Lot Status History</div>
            ${history.map(entry => {
              const paidAmount = Number(entry.paid_amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
              const refundAmount = Number(entry.refund_amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
              const companyAmount = Number(entry.company_amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
              const eventDate = entry.event_date || entry.created_at || 'N/A';
              return `
                <div style="border:1px solid #e5e7eb; border-radius:12px; padding:14px; margin-bottom:12px; background:#ffffff;">
                  <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start; margin-bottom:8px;">
                    <div>
                      <div style="font-size:13px; font-weight:700; color:#111827; text-transform:capitalize;">${escapeText(entry.event_type || 'Event')}</div>
                      <div style="font-size:12px; color:#64748b; margin-top:4px;">${escapeText(eventDate)}</div>
                    </div>
                    <div style="font-size:12px; color:#475569; text-align:right; line-height:1.5;">
                      <div>Paid: PHP ${paidAmount}</div>
                      <div>Refund: PHP ${refundAmount}</div>
                      <div>Company: PHP ${companyAmount}</div>
                    </div>
                  </div>
                  <div style="font-size:13px; color:#334155; margin-bottom:8px;">${escapeText(entry.remarks || '')}</div>
                  <div style="font-size:12px; color:#475569;">Previous owner: ${escapeText(entry.previous_owner_name || 'N/A')}</div>
                </div>
              `;
            }).join('')}
          </div>
        ` : '<div style="padding:16px; color:#6c757d; text-align:center;">No lot status history records found.</div>';

        content.innerHTML = paymentSection + historySection;
      })
      .catch(err => {
        console.error('Unexpected error:', err);
        content.innerHTML = '<div style="padding:16px; color:#dc3545; text-align:center;">Failed to load payment history due to unexpected error.</div>';
      });
  }

  function markTurnoverForLot(lotId, isPaid) {
    if (!isPaid) {
      alert('Turnover can only be set for fully paid lots.');
      return;
    }

    const lotInput = document.getElementById('turnover-lot-id');
    const dateInput = document.getElementById('turnover-date');
    const releasedInput = document.getElementById('turnover-title-released');
    const remarksInput = document.getElementById('turnover-remarks');

    if (!lotInput || !dateInput || !releasedInput || !remarksInput) {
      alert('Turnover form is not available.');
      return;
    }

    lotInput.value = String(lotId);
    dateInput.value = new Date().toISOString().slice(0, 10);
    releasedInput.checked = false;
    remarksInput.value = 'Client will claim ownership title at Main Office.';

    fetch(`${window.location.pathname}?fetch=turnover_info&lot_id=${lotId}`)
      .then(r => r.json())
      .then(data => {
        if (data?.success && data.turnover) {
          dateInput.value = data.turnover.turnover_date || dateInput.value;
          releasedInput.checked = Number(data.turnover.title_released || 0) === 1;
          remarksInput.value = data.turnover.remarks || remarksInput.value;
        }
      })
      .catch(err => console.error('Failed to preload turnover info:', err))
      .finally(() => showModalById('turnoverModal'));
  }

  function closeTurnoverModal() {
    hideModalById('turnoverModal');
  }

  function submitTurnoverUpdate() {
    const lotId = Number(document.getElementById('turnover-lot-id')?.value || 0);
    const turnoverDate = (document.getElementById('turnover-date')?.value || '').trim();
    const titleReleased = document.getElementById('turnover-title-released')?.checked ? '1' : '0';
    const remarks = (document.getElementById('turnover-remarks')?.value || '').trim();

    if (!lotId) {
      alert('Missing lot reference for turnover update.');
      return;
    }

    const fd = new FormData();
    fd.append('action', 'mark_turnover_complete');
    fd.append('lot_id', String(lotId));
    fd.append('turnover_date', turnoverDate || '');
    fd.append('title_released', titleReleased);
    fd.append('remarks', remarks);

    fetch(window.location.pathname, { method: 'POST', body: fd })
      .then(r => r.text())
      .then(text => {
        let res;
        try {
          res = JSON.parse(text);
        } catch (e) {
          const firstBrace = text.indexOf('{');
          const lastBrace = text.lastIndexOf('}');
          if (firstBrace !== -1 && lastBrace !== -1 && lastBrace > firstBrace) {
            res = JSON.parse(text.slice(firstBrace, lastBrace + 1));
          } else {
            throw new Error('Non-JSON response: ' + text.slice(0, 200));
          }
        }

        if (!res.success) {
          alert('Failed to update turnover: ' + (res.error || 'Unknown error'));
          return;
        }
        closeTurnoverModal();
        let statusText = res.message || 'Turnover details updated.';
        statusText += res.system_notified ? ' Client was notified in the system.' : ' Client system notification was not sent.';
        statusText += res.sms_sent ? ' SMS sent.' : (' SMS not sent' + (res.sms_error ? ': ' + res.sms_error : '.') );
        showMessage(statusText, true);
        loadPayments();
      })
      .catch(err => {
        console.error(err);
        alert('Failed to update turnover. ' + (err.message || 'Please check server logs.'));
      });
  }

  // ===========================
  // LOT OWNERS FUNCTIONS
  // ===========================
  function loadLotOwnerLocationOptions() {
    fetch(window.location.pathname + '?fetch=locations', { method: 'GET' })
      .then(r => r.json())
      .then(locations => {
        const filterSelect = document.getElementById('lot-owner-location-filter');
        if (!filterSelect) return;

        const currentFilter = filterSelect.value || '';

        const optionsHtml = ['<option value="">All Locations</option>']
          .concat((locations || []).map(loc => `<option value="${loc.id}">${loc.location_name}</option>`))
          .join('');

        filterSelect.innerHTML = optionsHtml;

        filterSelect.value = currentFilter;
      })
      .catch(err => console.error('Failed to load location options:', err));
  }

  function loadLotOwners() {
    const tbody = document.getElementById('lot-owners-tbody');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 20px; color: #666;">Loading lot owners...</td></tr>';

    const selectedLocation = document.getElementById('lot-owner-location-filter')?.value || '';
    const params = new URLSearchParams();
    params.append('fetch', 'all_lot_owners');
    if (selectedLocation) params.append('location_id', selectedLocation);

    fetch(window.location.pathname + '?' + params.toString(), { method: 'GET' })
      .then(response => response.json())
      .then(data => {
        if (!data || !data.owners || data.owners.length === 0) {
          tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 20px; color: #666;">No lot owners found.</td></tr>';
          return;
        }

        tbody.innerHTML = data.owners.map(owner => {
          return `
          <tr>
            <td style="padding: 15px; border-bottom: 1px solid #f0f0f0; font-size: 14px; color: #333;">${owner.owner_name || 'N/A'}</td>
            <td style="padding: 15px; border-bottom: 1px solid #f0f0f0; font-size: 14px; color: #333;">${owner.email || 'N/A'}</td>
            <td style="padding: 15px; border-bottom: 1px solid #f0f0f0; font-size: 14px; color: #333;">${owner.mobile_number || 'N/A'}</td>
            <td style="padding: 15px; border-bottom: 1px solid #f0f0f0; font-size: 14px; color: #333;">${owner.location_name || 'N/A'} - Block ${owner.block_number || 'N/A'}, Lot ${owner.lot_number || 'N/A'}</td>
            <td style="padding: 15px; border-bottom: 1px solid #f0f0f0; font-size: 14px; color: #333;">
              <select id="status-${owner.lot_id}" class="lot-status-select" style="padding: 6px 30px 6px 10px; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; width: 170px; min-width: 170px; max-width: 170px; box-sizing: border-box;" onchange="updateLotPaymentStatus(${owner.lot_id}, this.value)">
                <option value="Available" ${owner.status === 'Available' ? 'selected' : ''}>Available</option>
                <option value="Reserved" ${owner.status === 'Reserved' ? 'selected' : ''}>Reserved</option>
                <option value="Installments" ${owner.status === 'Installment' ? 'selected' : ''}>Installments</option>
                <option value="Paid" ${owner.status === 'Paid' ? 'selected' : ''}>Paid</option>
              </select>
            </td>
            <td style="padding: 15px; border-bottom: 1px solid #f0f0f0; font-size: 14px; color: #333;">
              <button class="btn-small" onclick="viewOwnerDetails(${owner.user_id || 0}, '${String(owner.owner_name || '').replace(/'/g, "\\'")}', '${String(owner.email || '').replace(/'/g, "\\'")}', '${String(owner.mobile_number || '').replace(/'/g, "\\'")}')">View Details</button>
              ${owner.user_id > 0 ? `<button class="btn-small btn-danger" onclick="removeLotOwner(${owner.lot_id})" style="background:#dc3545; color:#fff; border:1px solid #dc3545;">Remove Owner</button>` : ''}
            </td>
          </tr>
        `;
        }).join('');
      })
      .catch(error => {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 20px; color: #dc3545;">Failed to load lot owners.</td></tr>';
        console.error('Error loading lot owners:', error);
      });
  }

  function loadSurrenderedLots() {
    const tbody = document.getElementById('lot-status-tbody');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:20px; color:#666;">Loading surrendered lots...</td></tr>';

    fetch(window.location.pathname + '?fetch=surrendered_lots')
      .then(response => response.json())
      .then(records => {
        if (!records || records.length === 0) {
          tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:20px; color:#666;">No surrendered lot records found.</td></tr>';
          return;
        }

        tbody.innerHTML = records.map(record => {
          const lotLabel = record.location_name ? `${record.location_name} - ` : '';
          const paid = record.paid_amount ? `PHP ${Number(record.paid_amount).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}` : 'PHP 0.00';
          const refund = record.refund_amount ? ` / Refund: PHP ${Number(record.refund_amount).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}` : '';
          return `
          <tr>
            <td style="padding: 15px; border-bottom: 1px solid #f0f0f0; font-size: 14px; color: #333;">${record.event_date || 'N/A'}</td>
            <td style="padding: 15px; border-bottom: 1px solid #f0f0f0; font-size: 14px; color: #333;">${lotLabel}Block ${record.block_number || 'N/A'}, Lot ${record.lot_number || 'N/A'}</td>
            <td style="padding: 15px; border-bottom: 1px solid #f0f0f0; font-size: 14px; color: #333;">${record.previous_owner_name || 'N/A'}<br>${record.previous_owner_email || ''}</td>
            <td style="padding: 15px; border-bottom: 1px solid #f0f0f0; font-size: 14px; color: #333;">${paid}${refund}</td>
            <td style="padding: 15px; border-bottom: 1px solid #f0f0f0; font-size: 14px; color: #333;">${record.remarks || ''}</td>
          </tr>
        `;
        }).join('');
      })
      .catch(error => {
        console.error('Failed to load surrendered lots:', error);
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:20px; color:#dc3545;">Failed to load surrendered lots.</td></tr>';
      });
  }

  function updateLotPaymentStatus(lotId, newStatus) {
    const formData = new FormData();
    formData.append('action', 'update_lot_status');
    formData.append('lot_id', lotId);
    formData.append('status', newStatus);

    fetch(window.location.pathname, { method: 'POST', body: formData })
      .then(r => r.text())
      .then(text => {
        let res;
        try {
          res = JSON.parse(text);
        } catch (e) {
          const firstBrace = text.indexOf('{');
          const lastBrace = text.lastIndexOf('}');
          if (firstBrace !== -1 && lastBrace !== -1 && lastBrace > firstBrace) {
            res = JSON.parse(text.slice(firstBrace, lastBrace + 1));
          } else {
            throw new Error('Non-JSON response: ' + text.slice(0, 180));
          }
        }

        if (res.success) {
          showMessage('Payment status updated successfully', true);
          loadLotOwners();
          loadPayments();
        } else {
          alert('Failed to update payment status: ' + (res.error || 'Unknown error'));
        }
      })
      .catch(error => {
        console.error('Lot owner status response error:', error);
        alert('Failed to update payment status. ' + (error.message || 'Please check console.'));
      });
  }

  async function removeLotOwner(lotId) {
    const proceed = confirm('Remove the owner from this lot?');
    if (!proceed) return;

    const formData = new FormData();
    formData.append('action', 'remove_lot_owner');
    formData.append('lot_id', lotId);

    fetch(window.location.pathname, { method: 'POST', body: formData })
      .then(r => r.json())
      .then(res => {
        if (res.success) {
          showMessage('Owner removed successfully', true);
          loadPayments();
          loadLotOwners(); // Reload the list
        } else {
          alert('Failed to remove owner: ' + (res.error || 'Unknown error'));
        }
      })
      .catch(error => {
        alert('Failed to remove owner.');
        console.error('Error:', error);
      });
  }

  function viewOwnerDetails(userId, fallbackName = '', fallbackEmail = '', fallbackMobile = '') {
    const modal = document.getElementById('ownerDetailsModal');
    const content = document.getElementById('owner-details-content');
    if (!modal || !content) return;

    const safe = (value) => {
      const str = String(value == null ? '' : value);
      return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    };

    const renderOwner = (owner) => {
      const sourceText = owner.source ? ` (${safe(owner.source)})` : '';
      content.innerHTML = `
        <div style="display:grid; gap:8px;">
          <div style="padding:10px 12px; border:1px solid #e9ecef; border-radius:6px;"><strong>Owner Name:</strong> ${safe(owner.name || 'N/A')}</div>
          <div style="padding:10px 12px; border:1px solid #e9ecef; border-radius:6px;"><strong>Email:</strong> ${safe(owner.email || 'N/A')}</div>
          <div style="padding:10px 12px; border:1px solid #e9ecef; border-radius:6px;"><strong>Mobile:</strong> ${safe(owner.mobile || 'N/A')}</div>
          <div style="padding:10px 12px; border:1px solid #e9ecef; border-radius:6px;"><strong>Address:</strong> ${safe(owner.address || 'N/A')}${sourceText}</div>
        </div>
      `;
    };

    modal.style.display = 'flex';

    if (!userId || Number(userId) <= 0) {
      renderOwner({
        name: fallbackName || 'N/A',
        email: fallbackEmail || 'N/A',
        mobile: fallbackMobile || 'N/A',
        address: 'N/A',
        source: 'from approved reservation'
      });
      return;
    }

    content.innerHTML = '<div style="padding:10px 12px; border:1px solid #e9ecef; border-radius:6px; color:#6c757d;">Loading details...</div>';

    // Fetch and display owner details
    fetch(window.location.pathname + '?fetch=user&id=' + userId)
      .then(r => r.json())
      .then(user => {
        renderOwner({
          name: ((user.first_name || '') + ' ' + (user.last_name || '')).trim() || 'N/A',
          email: user.email || 'N/A',
          mobile: user.mobile_number || 'N/A',
          address: user.address || 'N/A'
        });
      })
      .catch(() => {
        content.innerHTML = '<div style="padding:10px 12px; border:1px solid #f5c2c7; border-radius:6px; color:#842029; background:#f8d7da;">Failed to fetch owner details.</div>';
      });
  }

  function closeOwnerDetailsModal() {
    const modal = document.getElementById('ownerDetailsModal');
    if (modal) modal.style.display = 'none';
  }

  // Lot owner page controls
  document.getElementById('refresh-lot-owners-btn')?.addEventListener('click', loadLotOwners);
  document.getElementById('lot-owner-location-filter')?.addEventListener('change', loadLotOwners);

  window.addEventListener('click', function(e) {
    const modal = document.getElementById('ownerDetailsModal');
    if (modal && e.target === modal) closeOwnerDetailsModal();
  });

  // ===========================
  // ACCOUNT MANAGEMENT
  // ===========================
  function showAccountType(type) {
    document.querySelectorAll('.account-section').forEach(section => section.classList.remove('active'));
    document.querySelectorAll('.account-type-nav a').forEach(tab => tab.classList.remove('active'));
    const sec = document.getElementById(type + '-accounts');
    const tab = document.getElementById(type + '-tab');
    if (sec) sec.classList.add('active');
    if (tab) tab.classList.add('active');
  }

  // Photo and location functions
  function previewPhoto(input, previewId) {
    const preview = document.getElementById(previewId);
    const file = input.files[0];
    
    if (file && preview) {
      const reader = new FileReader();
      reader.onload = function(e) {
        preview.innerHTML = `<img src="${e.target.result}" alt="Preview" class="photo-preview">`;
      };
      reader.readAsDataURL(file);
    } else if (preview) {
      preview.innerHTML = 'No Photo';
      preview.className = 'photo-placeholder';
    }
  }

  function getCurrentLocation() {
    const statusDiv = document.getElementById('location-status');
    if (!statusDiv) return;
    statusDiv.style.display = 'block';
    statusDiv.innerHTML = 'Getting location...';
    
    if (navigator.geolocation) {
      navigator.geolocation.getCurrentPosition(
        position => {
          const lat = document.getElementById('latitude');
          const lng = document.getElementById('longitude');
          if (lat && lng) {
            lat.value = position.coords.latitude;
            lng.value = position.coords.longitude;
          }
          statusDiv.className = 'location-status location-success';
          statusDiv.innerHTML = 'Location captured successfully!';
          setTimeout(() => statusDiv.style.display = 'none', 3000);
        },
        error => {
          statusDiv.className = 'location-status location-error';
          statusDiv.innerHTML = 'Error: ' + error.message;
        }
      );
    } else {
      statusDiv.className = 'location-status location-error';
      statusDiv.innerHTML = 'Geolocation is not supported by this browser.';
    }
  }

  function clearLocation() {
    const lat = document.getElementById('latitude');
    const lng = document.getElementById('longitude');
    const status = document.getElementById('location-status');
    if (lat) lat.value = '';
    if (lng) lng.value = '';
    if (status) status.style.display = 'none';
  }

  function getCurrentLocationAgent() {
    const statusDiv = document.getElementById('agent-location-status');
    if (!statusDiv) return;
    statusDiv.style.display = 'block';
    statusDiv.innerHTML = 'Getting location...';
    
    if (navigator.geolocation) {
      navigator.geolocation.getCurrentPosition(
        position => {
          const lat = document.getElementById('agent_latitude');
          const lng = document.getElementById('agent_longitude');
          if (lat && lng) {
            lat.value = position.coords.latitude;
            lng.value = position.coords.longitude;
          }
          statusDiv.className = 'location-status location-success';
          statusDiv.innerHTML = 'Location captured successfully!';
          setTimeout(() => statusDiv.style.display = 'none', 3000);
        },
        error => {
          statusDiv.className = 'location-status location-error';
          statusDiv.innerHTML = 'Error: ' + error.message;
        }
      );
    } else {
      statusDiv.className = 'location-status location-error';
      statusDiv.innerHTML = 'Geolocation is not supported by this browser.';
    }
  }

  function clearLocationAgent() {
    const lat = document.getElementById('agent_latitude');
    const lng = document.getElementById('agent_longitude');
    const status = document.getElementById('agent-location-status');
    if (lat) lat.value = '';
    if (lng) lng.value = '';
    if (status) status.style.display = 'none';
  }

  // ===========================
  // VIEW CLIENT MODAL
  // ===========================
  function viewProfile(id, type, evt, clientEmail = '', clientPhone = '') {
      // Hide modal after 3 seconds
      setTimeout(() => {
        modal.style.display = 'none';
      }, 3000);
    if (evt && typeof evt.preventDefault === 'function') evt.preventDefault();
    const modal   = document.getElementById('viewClientModal');
    const content = document.getElementById('viewClientContent');
    const title   = modal?.querySelector('h3');
    if (!modal || !content || !title) return;

    // Set modal title based on type
    let profileTitle = 'Profile';
    if (type === 'admin') profileTitle = 'Admin Profile';
    else if (type === 'agent') profileTitle = 'Agent Profile';
    else if (type === 'user') profileTitle = 'User Profile';
    else profileTitle = 'Client Profile';
    title.textContent = profileTitle;

    modal.style.display = 'flex';
    content.innerHTML = '<div style="color:#666;">Loading details…</div>';

    // guest (no account) from table row
    if (type === 'user' && (!id || id === 0)) {
      try {
        const btn       = evt?.target;
        const row       = btn?.closest('tr');
        const name      = row?.querySelector('td strong')?.innerText?.trim() || 'N/A';
        const emailText = (clientEmail || row?.querySelector('td:nth-child(2) a[href^="mailto:"]')?.innerText || '').trim();
        const phoneText = (clientPhone || row?.querySelector('td:nth-child(2) a[href^="tel:"]')?.innerText || '').trim();
        const contact   = [emailText, phoneText].filter(Boolean).join(' | ') || 'N/A';
        const location  = row?.querySelector('td:nth-child(3)')?.innerText?.trim() || 'N/A';
        const lot       = row?.querySelector('td:nth-child(4)')?.innerText?.trim() || 'N/A';
        const prefDate  = row?.querySelector('td:nth-child(5)')?.innerText?.trim() || 'N/A';

        const params = new URLSearchParams();
        params.append('fetch', 'lookup_client');
        if (emailText) params.append('email', emailText);
        if (phoneText) params.append('phone', phoneText);

        fetch(window.location.pathname + '?' + params.toString())
          .then(r => r.json())
          .then(data => {
            const account = data?.account || null;

            if (account && account.id) {
              let html = `<strong>Name:</strong> ${account.first_name || ''} ${account.middle_name ? account.middle_name + ' ' : ''}${account.last_name || ''}<br>`;
              if (account.username) html += `<strong>Username:</strong> ${account.username}<br>`;
              html += `<strong>Email:</strong> ${account.email || emailText || 'N/A'}<br>`;
              if (account.mobile_number) html += `<strong>Mobile:</strong> ${account.mobile_number}<br>`;
              html += `<strong>Address:</strong> ${account.address || 'N/A'}<br>`;
              if (account.created_at) {
                const created = new Date(account.created_at).toLocaleDateString();
                html += `<strong>Registered:</strong> ${created}<br>`;
              }
              html += `<div style="color:#166534;margin-top:8px;font-weight:600;">Matched existing client account.</div>`;
              html += `<div style="color:#6b7280;margin-top:4px;">Latest request: ${location} | ${lot} | ${prefDate}</div>`;
              content.innerHTML = html;
              return;
            }

            content.innerHTML = `
              <strong>Name:</strong> ${name}<br>
              <strong>Contact:</strong> ${contact}<br>
              <strong>Location:</strong> ${location}<br>
              <strong>Lot Details:</strong> ${lot}<br>
              <strong>Preferred Date:</strong> ${prefDate}<br>
              <div style="color:#dc3545;margin-top:8px;">No user account for this client.</div>
            `;
          })
          .catch(() => {
            content.innerHTML = `
              <strong>Name:</strong> ${name}<br>
              <strong>Contact:</strong> ${contact}<br>
              <strong>Location:</strong> ${location}<br>
              <strong>Lot Details:</strong> ${lot}<br>
              <strong>Preferred Date:</strong> ${prefDate}<br>
              <div style="color:#dc3545;margin-top:8px;">No user account for this client.</div>
            `;
          });
      } catch (e) {
        content.innerHTML = `<div style="color:#dc3545;">Couldn’t read row data.</div>`;
      }
      return;
    }

    // Registered user/admin/agent via endpoint
    if ((type === 'user' || type === 'admin' || type === 'agent') && id) {
      fetch(window.location.pathname + `?fetch=${type}&id=` + encodeURIComponent(id))
        .then(r => {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.json();
        })
        .then(account => {
          if (account && account.id) {
            let html = `<strong>Name:</strong> ${account.first_name || ''} ${account.middle_name ? account.middle_name + ' ' : ''}${account.last_name || ''}<br>`;
            if (account.username) html += `<strong>Username:</strong> ${account.username}<br>`;
            html += `<strong>Email:</strong> ${account.email || 'N/A'}<br>`;
            if (account.phone) html += `<strong>Mobile:</strong> ${account.phone}<br>`;
            if (account.mobile_number) html += `<strong>Mobile:</strong> ${account.mobile_number}<br>`;
            html += `<strong>Address:</strong> ${account.address || 'N/A'}<br>`;
            if (account.created_at) {
              const created = new Date(account.created_at).toLocaleDateString();
              html += `<strong>Registered:</strong> ${created}<br>`;
            }
            content.innerHTML = html;
          } else {
            content.innerHTML = `<div style=\"color:#dc3545;\">Client not found.</div>`;
          }
        })
        .catch(err => {
          content.innerHTML = `<div style=\"color:#dc3545;\">Error loading client: ${err.message}</div>`;
        });
    }
  }

  function closeViewClientModal() {
    const modal = document.getElementById('viewClientModal');
    if (modal) modal.style.display = 'none';
  }

  // ===========================
  // LOGOUT CONFIRMATION
  // ===========================
  function confirmLogout() {
    const modal = document.createElement('div');

    // Auto-fade any top flash-message (registration success, etc.)
    try {
        document.addEventListener('DOMContentLoaded', function(){
            const flash = document.querySelector('.flash-message');
            if (!flash) return;
            setTimeout(() => flash.classList.add('fade-out'), 4200);
            flash.addEventListener('transitionend', () => { try { flash.remove(); } catch(e){} });
        });
    } catch (e) { /* noop */ }

    modal.innerHTML = `
      <div style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 10000; display: flex; align-items: center; justify-content: center; font-family: 'Segoe UI', sans-serif;">
        <div style="background: white; padding: 0; border-radius: 8px; width: 400px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
          <div style="padding: 24px 24px 16px 24px;">
            <div style="display: flex; align-items: flex-start; gap: 16px;">
              <div style="width: 32px; height: 32px; background: #fff3cd; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 4px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                  <path d="M12 9V13M12 17H12.01M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12z" stroke="#856404" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </div>
              <div style="flex: 1;">
                <h3 style="margin: 0 0 12px 0; font-size: 20px; font-weight: 600; color: #212529;">Confirm Logout</h3>
                <p style="margin: 0; font-size: 16px; color: #6c757d;">Are you sure you want to logout? You will need to login again to access your dashboard.</p>
              </div>
            </div>
          </div>
          <div style="padding: 20px 24px 24px 24px; display: flex; justify-content: flex-end; gap: 12px; border-top: 1px solid #e9ecef;">
            <button id="cancel-logout" style="background: #f8f9fa; color: #6c757d; border: 1px solid #ced4da; padding: 10px 20px; border-radius: 4px; font-size: 16px; cursor: pointer; min-width: 90px;">Cancel</button>
            <button id="confirm-logout" style="background: #dc3545; color: white; border: 1px solid #dc3545; padding: 10px 20px; border-radius: 4px; font-size: 16px; cursor: pointer; min-width: 90px;">Logout</button>
          </div>
        </div>
      </div>
    `;
    document.body.appendChild(modal);
    
    function closeModal() {
      if (document.body.contains(modal)) document.body.removeChild(modal);
    }
    
    document.getElementById('cancel-logout').addEventListener('click', closeModal);
    document.getElementById('confirm-logout').addEventListener('click', () => window.location.href = 'logout.php');
    modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
  }

  // ===========================
  // ANALYTICS
  // ===========================
  function loadAnalyticsData() {
    applyAnalyticsFilters();
  }

  function loadAnalyticsKPIs() {
    applyAnalyticsFilters();
  }

  function updateSalesRangeLabel(dateFrom, dateTo, salesPeriod) {
    const rangeLabelEl = document.getElementById('kpi-total-sales-range-label');
    if (!rangeLabelEl) return;

    const periodTextMap = {
      daily: 'Daily',
      weekly: 'Weekly',
      monthly: 'Monthly',
      yearly: 'Yearly'
    };
    const periodText = periodTextMap[salesPeriod] || periodTextMap.monthly;

    if (dateFrom && dateTo) {
      rangeLabelEl.textContent = `Filtered: ${dateFrom} to ${dateTo} • ${periodText}`;
      return;
    }
    if (dateFrom) {
      rangeLabelEl.textContent = `Filtered: from ${dateFrom} • ${periodText}`;
      return;
    }
    if (dateTo) {
      rangeLabelEl.textContent = `Filtered: up to ${dateTo} • ${periodText}`;
      return;
    }
    rangeLabelEl.textContent = `${periodText} sales view`;
  }

  function renderAnalyticsKpis(data) {
    const totalSalesEl = document.getElementById('kpi-total-sales');
    const closedSalesEl = document.getElementById('kpi-closed-sales');
    const ongoingSalesEl = document.getElementById('kpi-ongoing-sales');
    const totalLotsEl  = document.getElementById('kpi-total-lots');
    const agentsEl     = document.getElementById('kpi-available-agents');
    const pendingEl    = document.getElementById('kpi-pending-documents');

    if (totalSalesEl) totalSalesEl.textContent = formatPesoFull(data.kpis.total_sales || 0);
    if (closedSalesEl) closedSalesEl.textContent = Number(data.kpis.closed_sales || 0).toLocaleString('en-PH');
    if (ongoingSalesEl) ongoingSalesEl.textContent = Number(data.kpis.ongoing_sales || 0).toLocaleString('en-PH');
    if (totalLotsEl)  totalLotsEl.textContent  = Number(data.kpis.total_lots || 0).toLocaleString('en-PH');
    if (agentsEl)     agentsEl.textContent     = Number(data.kpis.available_agents || 0).toLocaleString('en-PH');
    if (pendingEl)    pendingEl.textContent    = Number(data.kpis.pending_documents || 0).toLocaleString('en-PH');
  }

  function updateTopAgentsLabels(rankMode, salesScope) {
    const titleEl = document.getElementById('top-agents-title');
    const subtitleEl = document.getElementById('top-agents-subtitle');
    const primaryMetricLabelEl = document.getElementById('top-agents-primary-metric-label');

    const scopeTextMap = {
      all: 'All qualified sales',
      fully_paid_only: 'Fully paid only',
      not_fully_paid_only: 'Not fully paid only'
    };
    const scopeText = scopeTextMap[salesScope] || scopeTextMap.all;

    if (rankMode === 'encouragement') {
      if (titleEl) titleEl.textContent = 'Top Agents by Encouragement (Not Fully Paid)';
      if (primaryMetricLabelEl) primaryMetricLabelEl.textContent = 'Encouraged Buyers (Not Fully Paid)';
    } else {
      if (titleEl) titleEl.textContent = 'Top Agents by Sales';
      if (primaryMetricLabelEl) primaryMetricLabelEl.textContent = 'Sales Count';
    }

    if (subtitleEl) subtitleEl.textContent = `Sales scope: ${scopeText}`;
  }

  function loadTopAgents(page = 1) {
    const loading  = document.getElementById('top-agents-loading');
    const content  = document.getElementById('top-agents-content');
    const tbody    = document.getElementById('top-agents-tbody');

    if (loading) loading.style.display = 'block';
    if (content) content.style.display = 'none';

    const dateFrom   = document.getElementById('analytics_date_from')?.value;
    const dateTo     = document.getElementById('analytics_date_to')?.value;
    const locationId = document.getElementById('analytics_location')?.value;
    const rankMode = document.getElementById('top_agents_rank_mode')?.value || 'sales';
    const salesScope = document.getElementById('top_agents_sales_scope')?.value || 'all';

    updateTopAgentsLabels(rankMode, salesScope);

    const params = new URLSearchParams();
    params.append('fetch', 'top_agents');
    if (dateFrom) params.append('date_from', dateFrom);
    if (dateTo) params.append('date_to', dateTo);
    if (locationId) params.append('location_id', locationId);
    params.append('rank_mode', rankMode);
    params.append('sales_scope', salesScope);

    fetch(window.location.pathname + '?' + params.toString())
      .then(response => response.json())
      .then(agents => {
        if (loading) loading.style.display = 'none';
        if (content) content.style.display = 'block';

        if (!tbody) return;

        if (!agents.length) {
          tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;color:#666;">No agent data found for selected ranking and sales scope.</td></tr>`;
          return;
        }
        tbody.innerHTML = agents.map((agent, idx) => `
          <tr style="border-bottom: 1px solid #f0f0f0;">
            <td style="padding: 15px;">
              <div style="width: 30px; height: 30px; background: #2d482d; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 12px;">
                ${idx + 1}
              </div>
            </td>
            <td style="padding: 15px;">
              <div style="font-weight: 500;">${agent.name}</div>
              <div style="font-size: 12px; color: #666;">${agent.email}</div>
            </td>
            <td style="padding: 15px;">
              <span style="background: #e8f5e8; color: #2d482d; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: bold;">
                ${rankMode === 'encouragement' ? (agent.encouraged_not_fully_paid_count ?? 0) : (agent.sales_count ?? 0)}
              </span>
            </td>
            <td style="padding: 15px;">${agent.sold_lots_count ?? 0}</td>
            <td style="padding: 15px;">${agent.reserved_lots_count ?? 0}</td>
            <td style="padding: 15px;">${agent.ongoing_lots_count ?? 0}</td>
            <td style="padding: 15px;">${agent.cancelled_lots_count ?? 0}</td>
            <td style="padding: 15px; font-weight: 500;">${formatPesoFull(agent.total_amount || 0)}</td>
            <td style="padding: 15px;">${formatPesoFull(agent.avg_deal_size || 0)}</td>
          </tr>
        `).join('');
      })
      .catch(err => {
        if (loading) loading.style.display = 'none';
        if (content) content.style.display = 'block';
        if (tbody) {
          tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;color:#dc3545;">Failed to load agent data.</td></tr>`;
        }
        console.error(err);
      });
  }

  let lastMonthlySalesData = [];

  function loadMonthlySalesChart() {
    updateMonthlySalesChart([]);
  }

  function formatPesoCompact(value) {
    const n = Number(value) || 0;
    return 'PHP ' + new Intl.NumberFormat('en-PH', {
      notation: 'compact',
      maximumFractionDigits: 1
    }).format(n);
  }

  function formatPesoFull(value) {
    const n = Number(value) || 0;
    return 'PHP ' + new Intl.NumberFormat('en-PH', {
      maximumFractionDigits: 0
    }).format(n);
  }

  function setupResponsiveCanvas(canvas) {
    const wrap = document.getElementById('monthly-sales-chart-wrap') || canvas.parentElement;
    const cssWidth = Math.max(360, Math.floor((wrap?.clientWidth || 800) - 8));
    const cssHeight = 320;
    const dpr = window.devicePixelRatio || 1;

    canvas.style.width = cssWidth + 'px';
    canvas.style.height = cssHeight + 'px';
    canvas.width = Math.floor(cssWidth * dpr);
    canvas.height = Math.floor(cssHeight * dpr);

    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    return { ctx, width: cssWidth, height: cssHeight };
  }

  function updateMonthlySalesTitle(data, dateFrom, dateTo, salesPeriod) {
    const titleEl = document.getElementById('monthly-sales-title');
    if (!titleEl) return;

    const periodTextMap = {
      daily: 'Daily',
      weekly: 'Weekly',
      monthly: 'Monthly',
      yearly: 'Yearly'
    };
    const windowTextMap = {
      daily: 'Last 30 Days',
      weekly: 'Last 12 Weeks',
      monthly: 'Last 12 Months',
      yearly: 'Last 5 Years'
    };
    const periodText = periodTextMap[salesPeriod] || periodTextMap.monthly;
    const windowText = windowTextMap[salesPeriod] || windowTextMap.monthly;

    if (data && data.monthly_scope === 'default_period' && !dateFrom && !dateTo) {
      titleEl.textContent = `${periodText} Sales Trend (${windowText})`;
      return;
    }

    if (dateFrom && dateTo) {
      titleEl.textContent = `${periodText} Sales Trend (${dateFrom} to ${dateTo})`;
      return;
    }
    if (dateFrom) {
      titleEl.textContent = `${periodText} Sales Trend (From ${dateFrom})`;
      return;
    }
    if (dateTo) {
      titleEl.textContent = `${periodText} Sales Trend (Up to ${dateTo})`;
      return;
    }

    titleEl.textContent = `${periodText} Sales Trend`;
  }

  function applyAnalyticsFilters() {
    const normalizeDateInput = (value) => {
      const raw = String(value || '').trim();
      if (!raw) return '';
      if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return raw;

      const slash = raw.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
      if (slash) {
        const mm = String(Number(slash[1])).padStart(2, '0');
        const dd = String(Number(slash[2])).padStart(2, '0');
        return `${slash[3]}-${mm}-${dd}`;
      }

      const parsed = new Date(raw);
      if (!Number.isNaN(parsed.getTime())) {
        const y = parsed.getFullYear();
        const m = String(parsed.getMonth() + 1).padStart(2, '0');
        const d = String(parsed.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
      }

      return raw;
    };

    const getFilters = () => {
      const dateFrom = normalizeDateInput(document.getElementById('analytics_date_from')?.value || '');
      const dateTo = normalizeDateInput(document.getElementById('analytics_date_to')?.value || '');
      const locationId = document.getElementById('analytics_location')?.value || '';
      const salesPeriod = document.getElementById('analytics_sales_period')?.value || 'monthly';

      if (dateFrom && dateTo && dateFrom > dateTo) {
        alert('Date From cannot be later than Date To.');
        return null;
      }

      return { dateFrom, dateTo, locationId, salesPeriod };
    };

    const filters = getFilters();
    if (!filters) return;

    const dateFrom = filters.dateFrom;
    const dateTo = filters.dateTo;
    const locationId = filters.locationId;
    const salesPeriod = filters.salesPeriod;

    const params = new URLSearchParams();
    params.append('fetch', 'analytics');
    if (dateFrom) params.append('date_from', dateFrom);
    if (dateTo) params.append('date_to', dateTo);
    if (locationId) params.append('location_id', locationId);
    if (salesPeriod) params.append('sales_period', salesPeriod);

    fetch(window.location.pathname + '?' + params.toString())
      .then(response => response.json())
      .then(data => {
        if (!data || data.success === false) {
          alert((data && data.error) ? data.error : 'Failed to load analytics data.');
          return;
        }

        renderAnalyticsKpis(data);
        updateSalesRangeLabel(dateFrom, dateTo, salesPeriod);
        updateMonthlySalesTitle(data, dateFrom, dateTo, salesPeriod);

        const monthly = Array.isArray(data.monthly_sales) ? data.monthly_sales : [];
        lastMonthlySalesData = monthly;
        updateMonthlySalesChart(monthly);
        loadTopAgents(1);
      })
      .catch(err => {
        alert('Failed to load analytics data.');
        console.error(err);
      });
  }

  function updateMonthlySalesChart(monthlySalesData) {
    const canvas = document.getElementById('monthly-sales-chart');
    if (!canvas) return;
    const tooltip = document.getElementById('monthly-sales-tooltip');
    const wrap = document.getElementById('monthly-sales-chart-wrap');

    const { ctx, width, height } = setupResponsiveCanvas(canvas);
    const data = monthlySalesData.length ? monthlySalesData : [];
    if (canvas._animFrame) {
      cancelAnimationFrame(canvas._animFrame);
      canvas._animFrame = null;
    }

    const chart = {
      left: 70,
      right: width - 24,
      top: 20,
      bottom: height - 52
    };
    const plotWidth = Math.max(1, chart.right - chart.left);
    const plotHeight = Math.max(1, chart.bottom - chart.top);

    if (!data.length) {
      canvas._hitPoints = [];
      canvas._drawStatic = null;
      canvas._hoverIndex = -1;
      if (tooltip) tooltip.style.display = 'none';

      ctx.clearRect(0, 0, width, height);
      ctx.fillStyle = '#f7faf7';
      ctx.fillRect(0, 0, width, height);
      ctx.fillStyle = '#777';
      ctx.font = '600 14px Segoe UI, Arial, sans-serif';
      ctx.fillText('No sales data for selected filters.', chart.left + 8, height / 2);
      return;
    }

    const amounts = data.map(item => Number(item.amount) || 0);
    const maxAmount = Math.max(...amounts, 1);
    const minAmount = Math.min(...amounts, 0);
    const range = Math.max(1, maxAmount - minAmount);

    const xFor = (i) => {
      if (data.length === 1) return chart.left + (plotWidth / 2);
      return chart.left + (i / (data.length - 1)) * plotWidth;
    };
    const yFor = (amount, progress) => {
      const animatedAmount = minAmount + (amount - minAmount) * progress;
      const ratio = (animatedAmount - minAmount) / range;
      return chart.bottom - (ratio * plotHeight);
    };

    const drawFrame = (progress, hoverIndex = -1) => {
      ctx.clearRect(0, 0, width, height);

      const bgGrad = ctx.createLinearGradient(0, 0, 0, height);
      bgGrad.addColorStop(0, '#fcfdfc');
      bgGrad.addColorStop(1, '#f3f8f3');
      ctx.fillStyle = bgGrad;
      ctx.fillRect(0, 0, width, height);

      const ticks = 4;
      ctx.strokeStyle = '#e5e7eb';
      ctx.lineWidth = 1;
      ctx.fillStyle = '#6b7280';
      ctx.font = '11px Segoe UI, Arial, sans-serif';

      for (let t = 0; t <= ticks; t++) {
        const ratio = t / ticks;
        const y = chart.bottom - (ratio * plotHeight);
        const value = minAmount + (range * ratio);

        ctx.beginPath();
        ctx.moveTo(chart.left, y);
        ctx.lineTo(chart.right, y);
        ctx.stroke();

        ctx.textAlign = 'right';
        ctx.fillText(formatPesoCompact(value), chart.left - 10, y + 4);
      }

      const labelStep = Math.max(1, Math.ceil(data.length / 8));
      ctx.textAlign = 'center';
      data.forEach((item, i) => {
        if (i % labelStep !== 0 && i !== data.length - 1) return;
        const x = xFor(i);
        ctx.fillStyle = '#6b7280';
        ctx.fillText(item.period || item.month || item.label || '', x, chart.bottom + 22);
      });

      const points = data.map((item, i) => ({
        x: xFor(i),
        y: yFor(Number(item.amount) || 0, progress),
        amount: Number(item.amount) || 0,
        period: item.period || item.month || item.label || ''
      }));

      const areaGrad = ctx.createLinearGradient(0, chart.top, 0, chart.bottom);
      areaGrad.addColorStop(0, 'rgba(34, 139, 78, 0.28)');
      areaGrad.addColorStop(1, 'rgba(34, 139, 78, 0.04)');

      ctx.beginPath();
      ctx.moveTo(points[0].x, chart.bottom);
      points.forEach((p) => ctx.lineTo(p.x, p.y));
      ctx.lineTo(points[points.length - 1].x, chart.bottom);
      ctx.closePath();
      ctx.fillStyle = areaGrad;
      ctx.fill();

      ctx.beginPath();
      points.forEach((p, idx) => {
        if (idx === 0) ctx.moveTo(p.x, p.y);
        else ctx.lineTo(p.x, p.y);
      });
      ctx.strokeStyle = '#1e7a4a';
      ctx.lineWidth = 3;
      ctx.lineJoin = 'round';
      ctx.lineCap = 'round';
      ctx.stroke();

      points.forEach((p, i) => {
        if (i % labelStep !== 0 && i !== points.length - 1) return;
        ctx.beginPath();
        ctx.arc(p.x, p.y, 4, 0, Math.PI * 2);
        ctx.fillStyle = '#ffffff';
        ctx.fill();
        ctx.strokeStyle = '#1e7a4a';
        ctx.lineWidth = 2;
        ctx.stroke();
      });

      if (hoverIndex >= 0 && points[hoverIndex]) {
        const hp = points[hoverIndex];

        ctx.setLineDash([5, 4]);
        ctx.strokeStyle = 'rgba(30,122,74,0.35)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(hp.x, chart.top);
        ctx.lineTo(hp.x, chart.bottom);
        ctx.stroke();
        ctx.setLineDash([]);

        ctx.beginPath();
        ctx.arc(hp.x, hp.y, 7, 0, Math.PI * 2);
        ctx.fillStyle = '#ffffff';
        ctx.fill();
        ctx.strokeStyle = '#14532d';
        ctx.lineWidth = 3;
        ctx.stroke();
      }

      const last = points[points.length - 1];
      if (last) {
        const text = formatPesoFull(last.amount);
        ctx.font = 'bold 12px Segoe UI, Arial, sans-serif';
        const tw = ctx.measureText(text).width;
        const bx = Math.min(chart.right - tw - 18, last.x + 8);
        const by = Math.max(chart.top + 8, last.y - 28);
        ctx.fillStyle = 'rgba(30,122,74,0.95)';
        ctx.fillRect(bx, by, tw + 12, 20);
        ctx.fillStyle = '#ffffff';
        ctx.textAlign = 'center';
        ctx.fillText(text, bx + (tw + 12) / 2, by + 14);
      }

      if (progress >= 1) {
        canvas._hitPoints = points;
        canvas._drawStatic = (hoverIdx) => drawFrame(1, hoverIdx);
      }
    };

    const duration = 650;
    const start = performance.now();
    const animate = (now) => {
      const t = Math.min(1, (now - start) / duration);
      const p = 1 - Math.pow(1 - t, 3);
      drawFrame(p, canvas._hoverIndex ?? -1);
      if (t < 1) {
        canvas._animFrame = requestAnimationFrame(animate);
      }
    };

    if (!canvas._hoverBound) {
      canvas.addEventListener('mousemove', (ev) => {
        if (!canvas._hitPoints || !canvas._hitPoints.length || !canvas._drawStatic) return;

        const rect = canvas.getBoundingClientRect();
        const x = ev.clientX - rect.left;

        let nearest = -1;
        let minDx = Number.POSITIVE_INFINITY;
        for (let i = 0; i < canvas._hitPoints.length; i++) {
          const dx = Math.abs(canvas._hitPoints[i].x - x);
          if (dx < minDx) {
            minDx = dx;
            nearest = i;
          }
        }

        if (nearest < 0 || minDx > 28) {
          if (tooltip) tooltip.style.display = 'none';
          if ((canvas._hoverIndex ?? -1) !== -1) {
            canvas._hoverIndex = -1;
            canvas._drawStatic(-1);
          }
          return;
        }

        const point = canvas._hitPoints[nearest];
        if (tooltip && wrap && point) {
          tooltip.innerHTML = `<div style="font-weight:600; margin-bottom:2px;">${point.period}</div><div>${formatPesoFull(point.amount)}</div>`;
          tooltip.style.display = 'block';

          const tipWidth = tooltip.offsetWidth || 110;
          const left = Math.max(8, Math.min(point.x - (tipWidth / 2), (wrap.clientWidth - tipWidth - 8)));
          const top = Math.max(8, point.y - 56);
          tooltip.style.left = `${left}px`;
          tooltip.style.top = `${top}px`;
        }

        if ((canvas._hoverIndex ?? -1) !== nearest) {
          canvas._hoverIndex = nearest;
          canvas._drawStatic(nearest);
        }
      });

      canvas.addEventListener('mouseleave', () => {
        if (tooltip) tooltip.style.display = 'none';
        if ((canvas._hoverIndex ?? -1) !== -1 && canvas._drawStatic) {
          canvas._hoverIndex = -1;
          canvas._drawStatic(-1);
        }
      });

      canvas._hoverBound = true;
    }

    canvas._animFrame = requestAnimationFrame(animate);
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function formatDateReadable(raw) {
    const val = String(raw || '').trim();
    if (!val) return 'N/A';
    const d = new Date(val.length > 10 ? val : (val + 'T00:00:00'));
    if (Number.isNaN(d.getTime())) return escapeHtml(val);
    return d.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: '2-digit' });
  }

  function formatReportSalesPeriodLabel(rawPeriod) {
    const period = String(rawPeriod || '').toLowerCase();
    switch (period) {
      case 'daily': return 'Today';
      case 'weekly': return 'This Week';
      case 'yearly': return 'This Year';
      case 'custom': return 'Custom Date Range';
      case 'monthly':
      default:
        return 'This Month';
    }
  }

  function applyReportSalesPeriodPreset() {
    const periodEl = document.getElementById('report_sales_period');
    const fromEl = document.getElementById('report_date_from');
    const toEl = document.getElementById('report_date_to');
    if (!periodEl || !fromEl || !toEl) return;

    const period = String(periodEl.value || 'monthly').toLowerCase();
    if (period === 'custom') {
      fromEl.disabled = false;
      toEl.disabled = false;
      return;
    }

    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const toIso = (d) => {
      const y = d.getFullYear();
      const m = String(d.getMonth() + 1).padStart(2, '0');
      const day = String(d.getDate()).padStart(2, '0');
      return `${y}-${m}-${day}`;
    };

    let fromDate = new Date(today);
    if (period === 'weekly') {
      const day = today.getDay();
      const diffToMonday = day === 0 ? 6 : day - 1;
      fromDate.setDate(today.getDate() - diffToMonday);
    } else if (period === 'monthly') {
      fromDate = new Date(today.getFullYear(), today.getMonth(), 1);
    } else if (period === 'yearly') {
      fromDate = new Date(today.getFullYear(), 0, 1);
    }

    fromEl.value = toIso(fromDate);
    toEl.value = toIso(today);
    fromEl.disabled = true;
    toEl.disabled = true;
  }

  function getReportFilters() {
    applyReportSalesPeriodPreset();

    const salesPeriod = document.getElementById('report_sales_period')?.value || 'monthly';
    const dateFrom = document.getElementById('report_date_from')?.value || '';
    const dateTo = document.getElementById('report_date_to')?.value || '';
    const locationId = document.getElementById('report_location')?.value || '';
    const agentId = document.getElementById('report_agent')?.value || '';

    if (salesPeriod === 'custom' && dateFrom && dateTo && dateFrom > dateTo) {
      alert('Date From cannot be later than Date To.');
      return null;
    }

    return { dateFrom, dateTo, locationId, agentId, salesPeriod };
  }

  function loadPrintableReports() {
    const filters = getReportFilters();
    if (!filters) return;

    const params = new URLSearchParams();
    params.append('fetch', 'reports_data');
    if (filters.dateFrom) params.append('date_from', filters.dateFrom);
    if (filters.dateTo) params.append('date_to', filters.dateTo);
    if (filters.locationId) params.append('location_id', filters.locationId);
    if (filters.agentId) params.append('agent_id', filters.agentId);
    if (filters.salesPeriod) params.append('sales_period', filters.salesPeriod);

    fetch(window.location.pathname + '?' + params.toString())
      .then(res => res.json())
      .then(data => {
        if (!data || data.success === false) {
          alert((data && data.error) ? data.error : 'Failed to load reports data.');
          return;
        }
        renderPrintableReports(data);
      })
      .catch(err => {
        console.error(err);
        alert('Failed to load reports data.');
      });
  }

  function renderPrintableReports(data) {
    const meta = document.getElementById('reports-meta');
    const generated = data.generated_at || '';
    const f = data.filters || {};
    const rangeText = (f.date_from || f.date_to)
      ? `${f.date_from || '...'} to ${f.date_to || '...'}`
      : 'All dates';
    const periodText = formatReportSalesPeriodLabel(f.sales_period || 'monthly');
    const agentText = f.agent_name || 'All Agents';

    if (meta) {
      meta.textContent = `Generated: ${generated || 'N/A'} | Period: ${periodText} | Date range: ${rangeText} | Agent: ${agentText}`;
    }

    const kpis = data.kpis || {};
    const closedEl = document.getElementById('report-kpi-closed-sales');
    const totalSalesEl = document.getElementById('report-kpi-total-sales');
    const fullyPaidEl = document.getElementById('report-kpi-fully-paid');
    if (closedEl) closedEl.textContent = Number((data.closed_sales || []).length).toLocaleString('en-PH');
    if (totalSalesEl) totalSalesEl.textContent = formatPesoFull(kpis.total_sales || 0);
    if (fullyPaidEl) fullyPaidEl.textContent = Number((data.fully_paid_lots || []).length).toLocaleString('en-PH');

    const reportAgentSelect = document.getElementById('report_agent');
    if (reportAgentSelect) {
      const currentVal = reportAgentSelect.value || '';
      const agentOptions = ['<option value="">All Agents</option>']
        .concat((data.agents || []).map(a => `<option value="${a.id}">${escapeHtml(a.name || 'Agent')}</option>`));
      reportAgentSelect.innerHTML = agentOptions.join('');
      if (currentVal) reportAgentSelect.value = currentVal;
    }

    const fillRows = (tbodyId, rows, columns, emptyColspan) => {
      const tbody = document.getElementById(tbodyId);
      if (!tbody) return;
      if (!rows || !rows.length) {
        tbody.innerHTML = `<tr><td colspan="${emptyColspan}" style="padding:12px; color:#666;">No records found.</td></tr>`;
        return;
      }
      tbody.innerHTML = rows.map((r) => `<tr>${columns.map(col => `<td style="padding:10px 12px; border-bottom:1px solid #f0f0f0;">${col(r)}</td>`).join('')}</tr>`).join('');
    };

    fillRows(
      'report-agents-body',
      data.agents || [],
      [
        (r) => escapeHtml(r.name || 'Agent'),
        (r) => escapeHtml(r.email || '-'),
        (r) => escapeHtml(r.mobile || '-'),
        (r) => `${escapeHtml(r.status || 'active')} / ${(Number(r.availability || 0) === 1 ? 'available' : 'unavailable')}`,
      ],
      4
    );

    fillRows(
      'report-closed-body',
      data.closed_sales || [],
      [
        (r) => escapeHtml(`${r.property || 'N/A'} (${r.location_name || 'N/A'})`),
        (r) => escapeHtml(r.agent_name || 'Unassigned'),
        (r) => escapeHtml(r.owner_name || 'Client'),
        (r) => formatPesoFull(r.closed_amount || 0),
        (r) => formatDateReadable(r.closed_date || ''),
      ],
      5
    );

    fillRows(
      'report-fully-paid-body',
      data.fully_paid_lots || [],
      [
        (r) => escapeHtml(r.property || 'N/A'),
        (r) => escapeHtml(r.location_name || 'N/A'),
        (r) => escapeHtml(r.owner_name || 'Client'),
        (r) => formatPesoFull(r.total_paid || 0),
        (r) => formatDateReadable(r.closed_date || ''),
      ],
      5
    );

    fillRows(
      'report-agent-sold-body',
      data.agent_sold_lots || [],
      [
        (r) => escapeHtml(`${r.property || 'N/A'} (${r.location_name || 'N/A'})`),
        (r) => escapeHtml(r.agent_name || 'Unassigned'),
        (r) => escapeHtml(r.owner_name || 'Client'),
        (r) => formatPesoFull(r.closed_amount || 0),
        (r) => formatDateReadable(r.closed_date || ''),
      ],
      5
    );
  }

  function downloadReportsFile() {
    const area = document.getElementById('reports-print-area');
    if (!area) {
      alert('Nothing to download yet.');
      return;
    }

    const now = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    const stamp = `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}_${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`;

    const htmlContent = `
      <html>
        <head>
          <meta charset="utf-8">
          <title>Nuevo Puerta Reports</title>
          <style>
            body { font-family: Arial, sans-serif; padding: 16px; color: #111; }
            h3, h4 { color: #1f3d1f; margin: 0 0 8px; }
            table { width: 100%; border-collapse: collapse; font-size: 12px; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background: #f3f4f6; }
          </style>
        </head>
        <body>${area.innerHTML}</body>
      </html>
    `;

    const blob = new Blob([htmlContent], { type: 'text/html;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `admin_reports_${stamp}.html`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  }

  window.addEventListener('resize', () => {
    if (!lastMonthlySalesData.length) return;
    updateMonthlySalesChart(lastMonthlySalesData);
  });

  // ===========================
  // MISC HELPERS
  // ===========================
  function resetForm(formId) {
    const form = document.getElementById(formId);
    if (form) {
      form.reset();
      const previews = form.querySelectorAll('.photo-placeholder, .photo-preview');
      previews.forEach(preview => {
        preview.innerHTML = 'No Photo';
        preview.className = 'photo-placeholder';
      });
    }
  }

  // ===========================
  // EDIT ACCOUNT MODAL
  // ===========================
  function editAccount(id, type = 'admin') {
    const modal     = document.getElementById('editAccountModal');
    const fieldsDiv = document.getElementById('editAccountFields');
    const photoDiv  = document.getElementById('editAccountPhotoSection');

    if (!modal || !fieldsDiv || !photoDiv) return;

    document.getElementById('edit_account_id').value   = id;
    document.getElementById('edit_account_type').value = type;

    modal.style.display = 'flex';
    fieldsDiv.innerHTML = '<div style="color:#666;">Loading account details…</div>';
    photoDiv.innerHTML  = '';

    const url = `${window.location.pathname}?fetch=${encodeURIComponent(type)}&id=${encodeURIComponent(id)}`;

    fetch(url)
      .then(r => r.text())
      .then(text => {
        let account;
        try {
          account = JSON.parse(text);
        } catch (e) {
          console.error('Raw response from server:', text);
          fieldsDiv.innerHTML =
            '<div style="color:#dc3545;">Error loading account: Unexpected non-JSON response from server.</div>';
          return;
        }

        if (!account || !account.id) {
          fieldsDiv.innerHTML = '<div style="color:#dc3545;">Account not found.</div>';
          return;
        }

        const photoHtml = `
          <div class="form-group">
            <label>Profile Photo</label>
            <div style="text-align:center;">
              <div id="edit-photo-preview" class="photo-placeholder" style="margin:0 auto 10px;">
                ${
                  account.photo_path
                    ? `<img src="${account.photo_path}" alt="Profile" class="photo-preview">`
                    : 'No Photo'
                }
              </div>
              <input type="file" name="photo" accept="image/*"
                    onchange="previewPhoto(this, 'edit-photo-preview')">
            </div>
          </div>
        `;
        photoDiv.innerHTML = photoHtml;

        let html = `
          <div class="form-row-three">
            <div class="form-group">
              <label>First Name</label>
              <input type="text" name="first_name" value="${account.first_name || ''}" required>
            </div>
            <div class="form-group">
              <label>Middle Name</label>
              <input type="text" name="middle_name" value="${account.middle_name || ''}" placeholder="Middle Name">
            </div>
            <div class="form-group">
              <label>Last Name</label>
              <input type="text" name="last_name" value="${account.last_name || ''}" required>
            </div>
          </div>
        `;

        // Username (always show for admin, agent, user)
        html += `
          <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" value="${account.username || ''}" required>
          </div>
        `;

        html += `
          <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" value="${account.email || ''}" required>
          </div>
        `;

        if (type === 'agent') {
          html += `
            <div class="form-group">
              <label>Role</label>
              <input type="text" name="role" value="${account.role || ''}">
            </div>
          `;
        }

        if (type === 'agent') {
          html += `
            <div class="form-group">
              <label>Phone Number</label>
              <input type="text" name="phone" value="${account.phone || ''}">
            </div>
          `;
        }

        if (type === 'user') {
          html += `
            <div class="form-group">
              <label>Mobile Number</label>
              <input type="text" name="phone_number" value="${account.mobile_number || ''}">
            </div>
          `;
        }

        html += `
          <div class="form-group">
            <label>Address</label>
            <input type="text" name="address" value="${account.address || ''}">
          </div>
          <div class="form-group">
            <label>Change Password (leave blank to keep current)</label>
            <input type="password" name="password" placeholder="New Password">
          </div>
        `;

        fieldsDiv.innerHTML = html;
      })
      .catch(err => {
        fieldsDiv.innerHTML =
          `<div style="color:#dc3545;">Error loading account: ${err.message}</div>`;
      });
  }

  function closeEditAccountModal() {
    const modal = document.getElementById('editAccountModal');
    if (modal) modal.style.display = 'none';
  }

  // ===========================
  // EDIT LOT MODAL
  // ===========================
  function openEditLotModal(lot) {
    const modal     = document.getElementById('editLotModal');
    const fieldsDiv = document.getElementById('editLotFields');
    const idInput   = document.getElementById('edit_lot_id');
    if (!modal || !fieldsDiv || !idInput) return;

    idInput.value = lot.id;

    fieldsDiv.innerHTML = `
      <div class="form-group">
        <label>Block Number</label>
        <input type="text" name="block_number" value="${lot.block_number || ''}" required>
      </div>
      <div class="form-group">
        <label>Lot Number</label>
        <input type="text" name="lot_number" value="${lot.lot_number || ''}" required>
      </div>
      <div class="form-group">
        <label>Lot Size</label>
        <input type="text" name="lot_size" value="${lot.lot_size || ''}" required>
      </div>
      <div class="form-group">
        <label>Lot Price</label>
        <input type="text" name="lot_price" value="${formatLotPriceDisplay(lot.lot_price || '')}" required>
      </div>
      <div class="form-group">
        <label>Commission Amount</label>
        <input type="text" name="commission_amount" value="${formatLotPriceDisplay(lot.commission_amount || '')}" placeholder="0.00">
      </div>
      <div class="form-group">
        <label>Workflow Status</label>
        <div style="padding:8px 10px; border:1px solid #ddd; border-radius:6px; background:#f8f9fa; color:#2d4e1e; font-weight:600;">
          ${lot.workflow_stage || lot.status || 'Available'}
        </div>
        <input type="hidden" name="status" value="${lot.status || 'Available'}">
      </div>
      <input type="hidden" name="payment_type" value="${lot.payment_type || 'Not Applicable'}">
      <input type="hidden" name="payment_amount" value="${lot.payment_amount || ''}">
      <input type="hidden" name="payment_deadline" value="${lot.payment_deadline || ''}">
      <div class="form-group">
        <label>Location ID</label>
        <input type="text" name="location_id" value="${lot.location_id || ''}" required>
      </div>
    `;
    bindLotPriceFormatter(fieldsDiv.querySelector('input[name="lot_price"]'));
    bindLotPriceFormatter(fieldsDiv.querySelector('input[name="commission_amount"]'));
    modal.style.display = 'flex';
  }

  function closeEditLotModal() {
    const modal = document.getElementById('editLotModal');
    if (modal) modal.style.display = 'none';
  }

  // ===========================
  // BULK LOT DELETE + EXPORT
  // ===========================
  async function bulkDeleteLots() {
    const checkboxes = document.querySelectorAll('.lot-checkbox:checked');
    if (checkboxes.length === 0) {
      alert('Please select at least one lot to delete.');
      return;
    }
    const proceed = await showConfirmModal('Are you sure you want to delete the selected lots?');
    if (!proceed) return;

    const ids = Array.from(checkboxes).map(cb => cb.value);
    const formData = new FormData();
    formData.append('action', 'bulk_delete');
    formData.append('lot_ids', JSON.stringify(ids));

    fetch(window.location.pathname, { method: 'POST', body: formData })
      .then(r => r.json())
      .then(res => {
        if (res.success) {
          alert('Selected lots deleted!');
          const locSel = document.getElementById('location_id');
          loadLots(locSel ? locSel.value : '');
        } else {
          alert('Failed to delete lots: ' + (res.error || 'Unknown error'));
        }
      })
      .catch(() => alert('Failed to delete lots.'));
  }

// --- Mapper Globals ---
let pzInstance = null;
let svgMain = null, staticGroup = null, liveGroup = null;
let pinModalData = {
    lot_id: null,
    isDrawingMode: false,
    polygonPoints: [],
    selectedStatus: 'Available'
};

function selectLotStatus(status) {
    pinModalData.selectedStatus = status;
    
    // Update button styles
    const buttons = ['Available', 'Reserved', 'Sold'];
    buttons.forEach(btn => {
        const buttonEl = document.getElementById(`statusBtn_${btn}`);
        if (!buttonEl) return;
        if (btn === status) {
            buttonEl.style.background = { 'Available': '#28a745', 'Reserved': '#ffc107', 'Sold': '#dc3545' }[btn];
            buttonEl.style.color = btn === 'Reserved' ? '#333' : 'white';
        } else {
            buttonEl.style.background = 'white';
            buttonEl.style.color = { 'Available': '#28a745', 'Reserved': '#ffc107', 'Sold': '#dc3545' }[btn];
        }
    });

    if (pinModalData.polygonPoints.length > 0) {
        drawLivePolygon(pinModalData.polygonPoints.length > 2 && !pinModalData.isDrawingMode);
    }
}

// --- Open Mapper Modal ---
function openPinModal(lotId, lotData) {
    pinModalData.lot_id = lotId;
    document.getElementById('pinModal').style.display = 'flex';
    
    fetch(`?fetch=blueprint&lot_id=${lotId}`)
    .then(r => r.json())
    .then(data => {
        const img = document.getElementById('blueprintImage');
        const wrapper = document.getElementById('blueprint-wrapper');
        const layer = document.getElementById('draw-layer');
        const stage = document.getElementById('blueprint-stage');
        
        if (!data.blueprint) {
            alert("No blueprint found for this location. Please upload a blueprint first.");
            closePinModal();
            return;
        }

        img.src = data.blueprint; 
        layer.innerHTML = ''; 

        // Reset and init Panzoom
        if(pzInstance) {
            pzInstance.destroy();
            pzInstance = null;
        }
        wrapper.style.transform = 'scale(1) translate(0px, 0px)'; 
        
        // Wait for image to load to apply correct dimensions
        img.onload = () => {
            pzInstance = Panzoom(wrapper, { maxScale: 10, minScale: 0.5, step: 0.2, cursor: 'grab' });
            stage.onwheel = e => { e.preventDefault(); pzInstance.zoomWithWheel(e); };
            
            // Setup Native SVG Canvas overlay
            svgMain = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            svgMain.setAttribute('width', '100%');
            svgMain.setAttribute('height', '100%');
            svgMain.setAttribute('viewBox', '0 0 100 100'); // Calculates by % 0-100
            svgMain.setAttribute('preserveAspectRatio', 'none');
            svgMain.style.position = 'absolute';
            svgMain.style.top = '0';
            svgMain.style.left = '0';
            
            staticGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            liveGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            svgMain.appendChild(staticGroup);
            svgMain.appendChild(liveGroup);
            layer.appendChild(svgMain);

            // Draw existing background pins
            if(data.all_pins) {
                data.all_pins.forEach(pin => {
                    if(pin.coordinates && Array.isArray(pin.coordinates) && pin.lot_id != lotId) {
                        let poly = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
                        poly.setAttribute('points', pin.coordinates.map(pt => `${pt.x},${pt.y}`).join(' '));
                        
                        let stat = (pin.pin_status || 'Available').toLowerCase();
                        poly.setAttribute('fill', stat==='sold' ? 'rgba(220,53,69,0.4)' : (stat==='reserved' ? 'rgba(255,193,7,0.4)' : 'rgba(40,167,69,0.4)'));
                        poly.setAttribute('stroke', stat==='sold' ? 'red' : (stat==='reserved' ? 'gold' : 'green'));
                        poly.setAttribute('stroke-width', '0.2');
                        poly.setAttribute('vector-effect', 'non-scaling-stroke');
                        staticGroup.appendChild(poly);
                    }
                });
            }

            // Load active lot data
            pinModalData.polygonPoints = (data.pin && Array.isArray(data.pin)) ? data.pin : [];
            pinModalData.selectedStatus = data.pin_status || 'Available';
            
            const lotInfo = document.getElementById('pinModalLotInfo');
            if (lotInfo && data.lot) lotInfo.textContent = `Block ${data.lot.block_number} - Lot ${data.lot.lot_number}`;

            selectLotStatus(pinModalData.selectedStatus); 
            drawLivePolygon(pinModalData.polygonPoints.length > 2);
            
            pinModalData.isDrawingMode = false;
            const btn = document.getElementById('toggleDrawBtn');
            if (btn) {
                btn.textContent = 'Start Drawing Polygon';
                btn.style.background = '#28a745';
            }
            layer.style.pointerEvents = 'none'; // Allow panning map on default
        };
    })
    .catch(err => {
        console.error(err);
        alert('Error loading blueprint data.');
    });
}

// --- Toggle Drawing ---
function toggleDrawingMode() {
    const btn = document.getElementById('toggleDrawBtn');
    const layer = document.getElementById('draw-layer');
    const wrapper = document.getElementById('blueprint-wrapper');
    
    pinModalData.isDrawingMode = !pinModalData.isDrawingMode;

    if(pinModalData.isDrawingMode) {
        if (btn) {
            btn.textContent = 'Stop / Double Click to Finish';
            btn.style.background = '#dc3545';
        }
        layer.style.pointerEvents = 'auto'; // Block map panning, enable capturing clicks
        layer.style.cursor = 'crosshair';
        
        // Stop Panzoom from capturing drag events
        if (pzInstance) pzInstance.setOptions({ disablePan: true });

        pinModalData.polygonPoints = [];
        liveGroup.innerHTML = '';
        
        let startX, startY;
        layer.onpointerdown = e => { 
            e.stopPropagation();
            startX = e.clientX; 
            startY = e.clientY; 
        };
        
        layer.onpointerup = e => {
            e.stopPropagation();
            if(!pinModalData.isDrawingMode) return;
            // Prevent click triggers when dragging slightly
            if(Math.abs(e.clientX - startX) > 5 || Math.abs(e.clientY - startY) > 5) return; 
            
            const rect = layer.getBoundingClientRect();
            // Store percentages 0-100 for proper scaling
            pinModalData.polygonPoints.push({
                x: ((e.clientX - rect.left) / rect.width) * 100,
                y: ((e.clientY - rect.top) / rect.height) * 100
            });
            drawLivePolygon(false);
        };
        
        layer.ondblclick = e => {
            e.stopPropagation();
            if(pinModalData.polygonPoints.length > 2) {
                finishDrawing();
            }
        };
    } else {
        finishDrawing();
    }
}

function finishDrawing() {
    const btn = document.getElementById('toggleDrawBtn');
    const layer = document.getElementById('draw-layer');
    pinModalData.isDrawingMode = false;

    if (btn) {
        btn.textContent = 'Start Drawing Polygon';
        btn.style.background = '#28a745';
    }
    
    layer.style.pointerEvents = 'none'; // Re-enable map panning
    layer.style.cursor = 'default';
    if (pzInstance) pzInstance.setOptions({ disablePan: false });

    layer.onpointerdown = null; 
    layer.onpointerup = null; 
    layer.ondblclick = null;

    if(pinModalData.polygonPoints.length > 2) {
        drawLivePolygon(true); // Close the polygon
    } else {
        liveGroup.innerHTML = ''; // Abort if they didn't draw a valid polygon
        pinModalData.polygonPoints = [];
    }
}

// --- Render the Active Drawing ---
function drawLivePolygon(closed) {
    liveGroup.innerHTML = '';
    if(pinModalData.polygonPoints.length === 0) return;

    const ptsStr = pinModalData.polygonPoints.map(pt => `${pt.x},${pt.y}`).join(' ');
    const shape = document.createElementNS('http://www.w3.org/2000/svg', closed ? 'polygon' : 'polyline');
    
    const status = pinModalData.selectedStatus.toLowerCase();
    let color = status === 'sold' ? 'rgba(220,53,69,0.6)' : (status === 'reserved' ? 'rgba(255,193,7,0.6)' : 'rgba(40,167,69,0.6)');
    let stroke = status === 'sold' ? 'red' : (status === 'reserved' ? 'gold' : 'green');

    shape.setAttribute('points', ptsStr);
    shape.setAttribute('stroke', stroke);
    shape.setAttribute('stroke-width', closed ? '0.4' : '0.2');
    shape.setAttribute('vector-effect', 'non-scaling-stroke');
    shape.setAttribute('fill', closed ? color : 'none');
    if(!closed) shape.setAttribute('stroke-dasharray', '1,1');
    liveGroup.appendChild(shape);

    // Render little dots on corners while drawing
    if(!closed) {
        pinModalData.polygonPoints.forEach(pt => {
            let c = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
            c.setAttribute('cx', pt.x);
            c.setAttribute('cy', pt.y);
            c.setAttribute('r', '0.5');
            c.setAttribute('fill', 'white');
            c.setAttribute('stroke', stroke);
            c.setAttribute('stroke-width', '0.2');
            c.setAttribute('vector-effect', 'non-scaling-stroke');
            liveGroup.appendChild(c);
        });
    }
}

// --- Save Function ---
function savePinLocation() {
    if(pinModalData.polygonPoints.length < 3) { 
        alert("Please draw a valid shape (at least 3 points) first!"); 
        return; 
    }
    
    const fd = new FormData();
    fd.append('action', 'save_pin');
    fd.append('lot_id', pinModalData.lot_id);
    fd.append('polygon_coordinates', JSON.stringify(pinModalData.polygonPoints)); 
    fd.append('pin_status', pinModalData.selectedStatus); 
    
    fetch('', {method:'POST', body:fd}).then(r=>r.json()).then(res=>{
        if(res.success) { 
            alert("Pin and status saved successfully!"); 
            closePinModal();
            // Automatically refresh the lots table data behind the scenes
            const locId = document.getElementById('location_id').value;
            if (locId) loadLots(locId);
        } else { 
            alert("Database Error: " + res.error); 
        }
    }).catch(err => {
        console.error(err);
        alert("Request failed. Check console for details.");
    });
}

function closePinModal() {
    document.getElementById('pinModal').style.display = 'none';
    if(pzInstance) { 
        pzInstance.reset(); 
        pzInstance.setOptions({ disablePan: false });
        pzInstance.destroy(); 
        pzInstance = null; 
    }
}



  function loadOwnerAccountsForRegistration() {
    const ownerSelect = document.getElementById('register-owner-user');
    if (!ownerSelect) return;
    ownerSelect.innerHTML = '<option value="">Loading owner accounts...</option>';

    fetch(window.location.pathname + '?fetch=owner_users', { method: 'GET' })
      .then(r => r.json())
      .then(data => {
        const users = data?.users || [];
        ownerSelect.innerHTML = '<option value="">Select owner account</option>';
        users.forEach(u => {
          ownerSelect.innerHTML += `<option value="${u.id}">${u.name} (${u.email || 'No email'})</option>`;
        });
      })
      .catch(() => {
        ownerSelect.innerHTML = '<option value="">Failed to load owner accounts</option>';
      });
  }

  // ===========================
  // ADD LOCATION FUNCTIONS
  // ===========================
  let locationMap = null;
  let locationMarker = null;

  function openAddLocationModal() {
    const modal = document.getElementById('addLocationModal');
    modal.style.display = 'flex';
    
    // Initialize map after modal is visible
    setTimeout(() => {
      if (!locationMap) {
        locationMap = L.map('location-map').setView([6.9214, 122.0790], 13);
        
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          attribution: '© OpenStreetMap contributors'
        }).addTo(locationMap);

        locationMap.on('click', function(e) {
          const { lat, lng } = e.latlng;
          
          // Update form fields
          document.getElementById('new_latitude').value = lat;
          document.getElementById('new_longitude').value = lng;
          document.getElementById('new_latitude_display').value = lat.toFixed(6);
          document.getElementById('new_longitude_display').value = lng.toFixed(6);

          // Remove previous marker if exists
          if (locationMarker) {
            locationMap.removeLayer(locationMarker);
          }

          // Add new marker
          locationMarker = L.marker([lat, lng], {
            icon: L.icon({
              iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-red.png',
              shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
              iconSize: [25, 41],
              iconAnchor: [12, 41],
              popupAnchor: [1, -34],
              shadowSize: [41, 41]
            })
          }).addTo(locationMap);

          // Reverse geocoding
          fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`)
            .then(response => response.json())
            .then(data => {
              const name = data.display_name || 'Unknown location';
              document.getElementById('new_location_name').value = name;
            })
            .catch(() => {
              document.getElementById('new_location_name').value = 'Unknown';
            });
        });
      } else {
        locationMap.invalidateSize();
      }
    }, 100);
  }

  function closeAddLocationModal() {
    const modal = document.getElementById('addLocationModal');
    modal.style.display = 'none';
    
    // Clear form
    document.getElementById('locationForm').reset();
    document.getElementById('new_latitude_display').value = '';
    document.getElementById('new_longitude_display').value = '';
    removeBlueprintPreview();
    
    // Remove marker
    if (locationMarker && locationMap) {
      locationMap.removeLayer(locationMarker);
      locationMarker = null;
    }
  }

  function previewBlueprintFile(input) {
    const file = input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(e) {
      document.getElementById('blueprint-preview-img').src = e.target.result;
      document.getElementById('blueprint-preview-container').style.display = 'block';
      document.getElementById('blueprint-upload-area').style.borderColor = '#3e5f3e';
      document.getElementById('blueprint-upload-text').innerHTML = '<strong style="color:#3e5f3e;">' + file.name + '</strong>';
    };
    reader.readAsDataURL(file);
  }

  function removeBlueprintPreview() {
    document.getElementById('new_blueprint_file').value = '';
    document.getElementById('blueprint-preview-container').style.display = 'none';
    document.getElementById('blueprint-upload-area').style.borderColor = '#b0c4a8';
    document.getElementById('blueprint-upload-text').innerHTML = 'Click to upload a blueprint image<br><span style="font-size:12px;color:#999;">JPG, PNG, or GIF</span>';
  }

  function saveNewLocation(event) {
    event.preventDefault();

    const locationName = document.getElementById('new_location_name').value.trim();
    const latitude = document.getElementById('new_latitude').value;
    const longitude = document.getElementById('new_longitude').value;

    if (!locationName) {
      alert('Location name is required. Click the map to auto-fill it, or type a name.');
      return;
    }

    if (!latitude || !longitude) {
      alert('Please click on the map to select a location.');
      return;
    }

    const formData = new FormData();
    formData.append('action', 'save_location');
    formData.append('location_name', locationName);
    formData.append('latitude', latitude);
    formData.append('longitude', longitude);

    const blueprintFile = document.getElementById('new_blueprint_file').files[0];
    if (blueprintFile) {
      formData.append('blueprint', blueprintFile);
    }

    fetch(window.location.pathname, {
      method: 'POST',
      body: formData
    })
    .then(response => response.text())
    .then(text => {
      let data;
      try {
        data = JSON.parse(text);
      } catch (e) {
        const firstBrace = text.indexOf('{');
        const lastBrace = text.lastIndexOf('}');
        if (firstBrace !== -1 && lastBrace !== -1 && lastBrace > firstBrace) {
          data = JSON.parse(text.slice(firstBrace, lastBrace + 1));
        } else {
          throw new Error('Invalid response from server.');
        }
      }

      if (data.success) {
        alert(data.message || 'Location added successfully!');
        closeAddLocationModal();
        loadLocations(data.location_id || '');
        if (data.location_id) {
          loadLots(data.location_id);
        }
      } else {
        alert('Failed to save location: ' + (data.error || data.message || 'Unknown error'));
      }
    })
    .catch(error => {
      console.error('Error:', error);
      alert('An error occurred while saving the location. ' + (error.message || ''));
    });
  }

  async function deleteSelectedLocation() {
    const locationSelect = document.getElementById('location_id');
    if (!locationSelect || !locationSelect.value) {
      alert('Please select a location to delete.');
      return;
    }

    const selectedText = locationSelect.options[locationSelect.selectedIndex]?.text || 'this location';
    const confirmDelete = await showConfirmModal(`Delete location "${selectedText}"? This cannot be undone.`);
    if (!confirmDelete) return;

    const formData = new FormData();
    formData.append('action', 'delete_location');
    formData.append('location_id', locationSelect.value);

    fetch(window.location.pathname, {
      method: 'POST',
      body: formData
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        alert('Location deleted successfully!');
        loadLocations();
        loadLots('');
      } else {
        alert('Failed to delete location: ' + (data.error || data.message || 'Unknown error'));
      }
    })
    .catch(error => {
      console.error('Error:', error);
      alert('An error occurred while deleting the location.');
    });
  }

  function exportAnalytics() {
    const normalizeDateInput = (value) => {
      const raw = String(value || '').trim();
      if (!raw) return '';
      if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return raw;

      const slash = raw.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
      if (slash) {
        const mm = String(Number(slash[1])).padStart(2, '0');
        const dd = String(Number(slash[2])).padStart(2, '0');
        return `${slash[3]}-${mm}-${dd}`;
      }

      const parsed = new Date(raw);
      if (!Number.isNaN(parsed.getTime())) {
        const y = parsed.getFullYear();
        const m = String(parsed.getMonth() + 1).padStart(2, '0');
        const d = String(parsed.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
      }

      return raw;
    };

    const dateFrom = normalizeDateInput(document.getElementById('analytics_date_from')?.value || '');
    const dateTo = normalizeDateInput(document.getElementById('analytics_date_to')?.value || '');
    const locationId = document.getElementById('analytics_location')?.value || '';
    const salesPeriod = document.getElementById('analytics_sales_period')?.value || 'monthly';

    if (dateFrom && dateTo && dateFrom > dateTo) {
      alert('Date From cannot be later than Date To.');
      return;
    }

    const params = new URLSearchParams();
    params.append('export', 'analytics');
    if (dateFrom) params.append('date_from', dateFrom);
    if (dateTo) params.append('date_to', dateTo);
    if (locationId) params.append('location_id', locationId);
    if (salesPeriod) params.append('sales_period', salesPeriod);

    window.location.href = window.location.pathname + '?' + params.toString();
  }

  // Mark all lot status records as read (Admin)
  function markAllLotStatusAsReadAdmin() {
    fetch(window.location.href, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: 'action=mark_lot_status_read'
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        location.reload();
      } else {
        alert('Error marking as read: ' + (data.message || 'Unknown error'));
      }
    })
    .catch(error => {
      console.error('Error:', error);
      alert('Error marking as read');
    });
  }

  function markAllNotificationsAsRead() {
    fetch(window.location.href, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: 'action=mark_notifications_read'
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        location.reload();
      } else {
        alert('Error marking notifications as read: ' + (data.message || 'Unknown error'));
      }
    })
    .catch(error => {
      console.error('Error:', error);
      alert('Error marking notifications as read');
    });
  }
  </script>

  </body>
  </html>