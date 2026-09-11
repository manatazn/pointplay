<?php
// ==========================================
// POINT PLAY - SERVER SIDE LOGIC (index.php)
// ==========================================

// Ensure data directory exists
$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

function getFile($name) {
    global $dataDir;
    return $dataDir . '/' . $name . '.json';
}

function safeRead($file, $default = null) {
    if (!file_exists($file)) return $default;
    $fp = fopen($file, 'r');
    if (!$fp) return $default;
    flock($fp, LOCK_SH);
    $size = filesize($file);
    $content = $size > 0 ? fread($fp, $size) : '';
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($content, true);
    return $data !== null ? $data : $default;
}

function safeWrite($file, $data) {
    $tempFile = $file . '.tmp.' . uniqid();
    $fp = fopen($tempFile, 'w');
    if ($fp) {
        flock($fp, LOCK_EX);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
        flock($fp, LOCK_UN);
        fclose($fp);
        rename($tempFile, $file); // Atomic replacement
        return true;
    }
    return false;
}

function checkLevelUp(&$user) {
    $xp = $user['totalXp'];
    $newLevel = 1;
    if ($xp >= 20000) $newLevel = 10;
    elseif ($xp >= 3000) $newLevel = 5;
    elseif ($xp >= 1500) $newLevel = 4;
    elseif ($xp >= 750) $newLevel = 3;
    elseif ($xp >= 250) $newLevel = 2;
    $user['level'] = max($user['level'], $newLevel);
}

function checkReferralProgress($user) {
    if (!empty($user['referredBy']) && empty($user['referralRewardGranted'])) {
        if ($user['totalAdsWatched'] >= 25 && $user['tasksCompleted'] >= 5) {
            
            // Mark user as processed
            $user['referralRewardGranted'] = true;
            $userFile = getFile('user_' . $user['tgId']);
            safeWrite($userFile, $user);
            
            // Grant reward to referrer
            $referrerFile = getFile('user_' . $user['referredBy']);
            $referrer = safeRead($referrerFile, null);
            if ($referrer) {
                $referrer['xp'] += 250;
                $referrer['totalXp'] += 250;
                $referrer['usdBalance'] += 0.025;
                $referrer['referralHistory'][] = [
                    'text' => 'Referral Bonus',
                    'xp' => 250,
                    'usd' => 0.025,
                    'user' => $user['firstName'],
                    'date' => date('M j, Y')
                ];
                checkLevelUp($referrer);
                safeWrite($referrerFile, $referrer);
            }
        }
    }
}

// Process POST actions from Frontend
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['action'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid payload']);
        exit;
    }

    $action = $input['action'];
    $tgId = $input['tgId'] ?? '123456789';
    $tgData = $input['tgData'] ?? ['first_name' => 'Point Play User'];
    $startapp = $input['startapp'] ?? null;

    $userFile = getFile('user_' . $tgId);
    $user = safeRead($userFile, null);

    // 1. Create or Initialize User
    if (!$user) {
        $user = [
            'tgId' => $tgId,
            'firstName' => $tgData['first_name'] ?? 'User',
            'lastName' => $tgData['last_name'] ?? '',
            'username' => $tgData['username'] ?? '',
            'photoUrl' => $tgData['photo_url'] ?? '',
            'xp' => 0, 'totalXp' => 0, 'usdBalance' => 0.00,
            'level' => 1, 'adsWatchedToday' => 0, 'totalAdsWatched' => 0,
            'tasksCompleted' => 0, 'boxesOpened' => 0, 'streak' => 1,
            'lastResetDay' => gmdate('Y-m-d'),
            'withdrawHistory' => [],
            'referredBy' => null,
            'referralRewardGranted' => false,
            'referrals' => [],
            'referralHistory' => [],
            'missions' => [
                'dailyReward' => ['completed' => true, 'claimed' => false, 'reward' => 5, 'type' => 'boolean'],
                'watch5' => ['target' => 5, 'claimed' => false, 'reward' => 20, 'type' => 'progress'],
                'watch30' => ['target' => 30, 'claimed' => false, 'reward' => 50, 'type' => 'progress'],
                'all' => ['claimed' => false, 'reward' => 100, 'type' => 'meta']
            ],
            'game' => [
                'seedDate' => gmdate('Y-m-d'),
                'levelsCompleted' => array_fill(0, 10, false),
                'stars' => array_fill(0, 10, 0),
                'powerups' => ['rocket' => 1, 'bomb' => 1],
                'adLimits' => ['rocket' => 0, 'bomb' => 0]
            ]
        ];

        // Process Referral Link
        if ($startapp && $startapp != $tgId) {
            $referrerFile = getFile('user_' . $startapp);
            $referrer = safeRead($referrerFile, null);
            if ($referrer) {
                $user['referredBy'] = $startapp;
                if (!in_array($tgId, $referrer['referrals'])) {
                    $referrer['referrals'][] = $tgId;
                    safeWrite($referrerFile, $referrer);
                }
            }
        }
        safeWrite($userFile, $user);
    }

    // 2. Daily Reset Check
    $today = gmdate('Y-m-d');
    if ($user['lastResetDay'] !== $today) {
        $diff = (strtotime($today) - strtotime($user['lastResetDay'])) / 86400;
        if ($diff == 1) {
            $user['streak'] = ($user['streak'] >= 7) ? 1 : $user['streak'] + 1;
        } else {
            $user['streak'] = 1;
        }
        $user['adsWatchedToday'] = 0;
        $user['lastResetDay'] = $today;
        
        $streakRewards = [5, 10, 15, 20, 25, 30, 50];
        $user['missions']['dailyReward']['claimed'] = false;
        $user['missions']['dailyReward']['reward'] = $streakRewards[$user['streak'] - 1];
        $user['missions']['watch5']['claimed'] = false;
        $user['missions']['watch30']['claimed'] = false;
        $user['missions']['all']['claimed'] = false;
        
        $user['game']['seedDate'] = $today;
        $user['game']['levelsCompleted'] = array_fill(0, 10, false);
        $user['game']['stars'] = array_fill(0, 10, 0);
        $user['game']['adLimits'] = ['rocket' => 0, 'bomb' => 0];
        
        safeWrite($userFile, $user);
    }

    // 3. Routing Actions
    if ($action === 'sync') {
        echo json_encode(['success' => true, 'data' => ['user' => $user, 'missions' => $user['missions'], 'game' => $user['game']]]);
        exit;
    }

    if ($action === 'watch_ad') {
        $user['xp'] += 10;
        $user['totalXp'] += 10;
        $user['usdBalance'] += 0.0015;
        $user['adsWatchedToday'] += 1;
        $user['totalAdsWatched'] += 1;
        checkLevelUp($user);
        safeWrite($userFile, $user);
        checkReferralProgress($user);
        echo json_encode(['success' => true, 'data' => ['user' => $user, 'missions' => $user['missions'], 'game' => $user['game']]]);
        exit;
    }

    if ($action === 'claim_mission') {
        $key = $input['key'];
        
        if ($key === 'all') {
            $allClaimed = ($user['missions']['dailyReward']['claimed'] && $user['missions']['watch5']['claimed'] && $user['missions']['watch30']['claimed']);
            if ($allClaimed && !$user['missions']['all']['claimed']) {
                $user['missions']['all']['claimed'] = true;
                $user['xp'] += $user['missions']['all']['reward'];
                $user['totalXp'] += $user['missions']['all']['reward'];
                $user['tasksCompleted'] += 1;
            }
        } elseif (isset($user['missions'][$key]) && !$user['missions'][$key]['claimed']) {
            $user['missions'][$key]['claimed'] = true;
            $user['xp'] += $user['missions'][$key]['reward'];
            $user['totalXp'] += $user['missions'][$key]['reward'];
            $user['tasksCompleted'] += 1;
        }

        checkLevelUp($user);
        safeWrite($userFile, $user);
        checkReferralProgress($user);
        echo json_encode(['success' => true, 'data' => ['user' => $user, 'missions' => $user['missions'], 'game' => $user['game']]]);
        exit;
    }

    if ($action === 'open_box') {
        $type = $input['type'];
        $costs = ['bronze' => 10000, 'silver' => 50000, 'gold' => 100000];
        $cost = $costs[$type] ?? 0;
        $reward = 0;
        $isJackpot = mt_rand(1, 10000) == 10000;
        
        if ($user['xp'] >= $cost && $cost > 0) {
            $user['xp'] -= $cost;
            $user['boxesOpened'] += 1;
            if ($type === 'bronze') $reward = $isJackpot ? 1.00 : 0.10;
            if ($type === 'silver') $reward = $isJackpot ? 7.00 : 0.50;
            if ($type === 'gold') $reward = $isJackpot ? 15.00 : 1.00;
            $user['usdBalance'] += $reward;
            safeWrite($userFile, $user);
        }
        echo json_encode(['success' => true, 'reward' => $reward, 'isJackpot' => $isJackpot, 'data' => ['user' => $user, 'missions' => $user['missions'], 'game' => $user['game']]]);
        exit;
    }

    if ($action === 'withdraw') {
        $amount = floatval($input['amount']);
        $address = $input['address'];
        if ($amount >= 10 && $user['usdBalance'] >= $amount) {
            $user['usdBalance'] -= $amount;
            $record = [
                'id' => '#' . strtoupper(substr(md5(uniqid()), 0, 6)),
                'date' => date('M j, Y'),
                'amount' => $amount,
                'address' => substr($address, 0, 6) . '...' . substr($address, -4),
                'status' => 'Pending'
            ];
            array_unshift($user['withdrawHistory'], $record);
            safeWrite($userFile, $user);
            echo json_encode(['success' => true, 'data' => ['user' => $user, 'missions' => $user['missions'], 'game' => $user['game']]]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid withdrawal']);
        }
        exit;
    }

    if ($action === 'get_referrals') {
        $total = count($user['referrals']);
        $pending = 0;
        $approved = 0;
        $list = [];
        foreach ($user['referrals'] as $refId) {
            $r = safeRead(getFile('user_' . $refId), null);
            if ($r) {
                $ads = $r['totalAdsWatched'] ?? 0;
                $tasks = $r['tasksCompleted'] ?? 0;
                $isApproved = ($ads >= 25 && $tasks >= 5);
                if ($isApproved) $approved++; else $pending++;
                
                $list[] = [
                    'name' => $r['firstName'],
                    'username' => $r['username'],
                    'status' => $isApproved ? 'Approved' : 'Pending',
                    'ads' => min(25, $ads),
                    'tasks' => min(5, $tasks)
                ];
            }
        }
        echo json_encode([
            'success' => true,
            'data' => [
                'total' => $total, 'pending' => $pending, 'approved' => $approved,
                'list' => $list, 'history' => $user['referralHistory']
            ]
        ]);
        exit;
    }

    if ($action === 'save_game') {
        $user['game'] = $input['game'];
        safeWrite($userFile, $user);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Point Play - Telegram Mini App</title>
  
  <script src="https://telegram.org/js/telegram-web-app.js"></script>
  <script src="https://sad.adsgram.ai/js/sad.min.js"></script>
  
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800;900&display=swap" rel="stylesheet">

  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { sans: ['Outfit', 'sans-serif'] },
          colors: {
            crypto: {
              dark: '#050511',     
              card: '#0a0b1a',     
              primary: '#3b82f6',  
              glow: '#00f0ff',     
              gold: '#ffb800',     
              silver: '#e2e8f0',   
              bronze: '#cd7f32'    
            }
          },
          animation: {
            'blob': 'blob 7s infinite',
            'pulse-fast': 'pulse 1.5s cubic-bezier(0.4, 0, 0.6, 1) infinite',
            'float-up': 'floatUp 2s ease-out forwards',
            'pop': 'pop 0.3s ease-out forwards',
            'shimmer': 'shimmer 2s infinite'
          },
          keyframes: {
            blob: {
              '0%': { transform: 'translate(0px, 0px) scale(1)' },
              '33%': { transform: 'translate(30px, -50px) scale(1.1)' },
              '66%': { transform: 'translate(-20px, 20px) scale(0.9)' },
              '100%': { transform: 'translate(0px, 0px) scale(1)' },
            },
            floatUp: {
              '0%': { transform: 'translateY(0) scale(1)', opacity: 1 },
              '100%': { transform: 'translateY(-100px) scale(0.5)', opacity: 0 }
            },
            pop: {
              '0%': { transform: 'scale(1)' },
              '50%': { transform: 'scale(1.3)' },
              '100%': { transform: 'scale(0)', opacity: 0 }
            },
            shimmer: {
              '0%': { transform: 'translateX(-100%)' },
              '100%': { transform: 'translateX(100%)' }
            }
          }
        }
      }
    }
  </script>

  <style>
    body {
      background-color: #050511;
      color: #f8fafc;
      overflow-x: hidden;
      -webkit-touch-callout: none;
      -webkit-user-select: none;
      user-select: none;
    }

    body.in-game {
      overflow: hidden !important;
      touch-action: none !important;
      position: fixed;
      inset: 0;
      width: 100vw;
      height: 100vh;
    }
    body.in-game #main-header,
    body.in-game #bottom-nav {
      display: none !important;
    }
    body.in-game #app-content {
      padding: 0 !important;
      margin: 0 !important;
    }

    .bg-orb-1 {
      position: fixed; top: -10%; left: -10%; width: 50vw; height: 50vw;
      background: radial-gradient(circle, rgba(59, 130, 246, 0.15) 0%, rgba(0, 0, 0, 0) 70%);
      z-index: -1; filter: blur(40px);
    }
    .bg-orb-2 {
      position: fixed; bottom: -10%; right: -10%; width: 60vw; height: 60vw;
      background: radial-gradient(circle, rgba(0, 240, 255, 0.1) 0%, rgba(0, 0, 0, 0) 70%);
      z-index: -1; filter: blur(50px);
    }

    input { user-select: auto !important; }
    img { pointer-events: none; }
    ::-webkit-scrollbar { width: 0px; background: transparent; }

    .glass-card {
      background: linear-gradient(145deg, rgba(20, 22, 45, 0.6) 0%, rgba(10, 11, 26, 0.8) 100%);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border: 1px solid rgba(255, 255, 255, 0.05);
      box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.3);
    }
    
    .glass-button {
      background: linear-gradient(135deg, rgba(59,130,246,0.2) 0%, rgba(0,240,255,0.1) 100%);
      border: 1px solid rgba(0,240,255,0.3);
      box-shadow: 0 0 15px rgba(0,240,255,0.1) inset;
    }

    .fade-in { animation: fadeIn 0.3s ease-out forwards; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

    .nav-active { color: #00f0ff !important; transform: translateY(-2px); }
    .nav-active i { filter: drop-shadow(0 0 8px rgba(0, 240, 255, 0.6)); }
    .nav-active::before {
      content: ''; position: absolute; top: -10px; left: 50%; transform: translateX(-50%);
      width: 20px; height: 4px; background: #00f0ff; border-radius: 4px;
      box-shadow: 0 0 12px #00f0ff, 0 0 20px #3b82f6;
    }

    #bottom-nav {
      transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease-in-out;
    }
    .nav-hidden {
      transition: none !important;
      transform: translate(-50%, 150px) !important;
      opacity: 0 !important;
      pointer-events: none !important;
    }

    .btn-3d {
      background: linear-gradient(to bottom, #3b82f6, #2563eb);
      border-bottom: 3px solid #1e3a8a;
      transition: all 0.1s;
    }
    .btn-3d:active {
      transform: translateY(3px);
      border-bottom-width: 0px;
      margin-bottom: 3px;
    }

    #toast-container {
      position: fixed; top: 1.5rem; left: 50%; transform: translate(-50%, -150%) scale(0.9);
      width: 90%; max-width: 380px; z-index: 999999;
      transition: all 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
      opacity: 0; pointer-events: none;
    }
    .toast-show { transform: translate(-50%, 0) scale(1) !important; opacity: 1 !important; }

    .box-bronze { background: linear-gradient(135deg, rgba(205,127,50,0.1), rgba(139,69,19,0.2)); border: 1px solid rgba(205,127,50,0.4); }
    .box-silver { background: linear-gradient(135deg, rgba(226,232,240,0.1), rgba(148,163,184,0.2)); border: 1px solid rgba(226,232,240,0.4); }
    .box-gold { background: linear-gradient(135deg, rgba(255,184,0,0.15), rgba(217,119,6,0.25)); border: 1px solid rgba(255,184,0,0.5); }

    /* Game Specific Styles */
    #game-play-container.fullscreen-mode {
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      width: 100vw; height: 100vh;
      z-index: 99999;
      background: #050511;
      padding: 10px;
      padding-bottom: env(safe-area-inset-bottom, 20px);
      display: flex;
      flex-direction: column;
      box-sizing: border-box;
    }

    #game-canvas-container {
      flex: 1 1 auto;
      width: 100%;
      height: 100%;
      background: radial-gradient(circle at center, #0f172a 0%, #050511 100%);
      border: 2px solid rgba(0, 240, 255, 0.2);
      border-radius: 1.5rem;
      overflow: hidden;
      position: relative;
      box-shadow: 0 0 30px rgba(0, 240, 255, 0.05) inset;
    }
    canvas { width: 100%; height: 100%; touch-action: none; display: block; }

    .level-node {
      width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
      font-weight: 900; font-size: 1.25rem; position: relative; z-index: 10; transition: transform 0.2s;
    }
    .level-node.completed { background: linear-gradient(135deg, #3b82f6, #00f0ff); color: #050511; box-shadow: 0 0 20px rgba(0, 240, 255, 0.4); }
    .level-node.current { background: #050511; border: 3px solid #00f0ff; color: #00f0ff; box-shadow: 0 0 20px rgba(0, 240, 255, 0.6); animation: pulse 2s infinite; }
    .level-node.locked { background: #1e293b; border: 2px solid #334155; color: #475569; }
    
    .path-line { position: absolute; width: 6px; background: #334155; z-index: 5; }
    .path-line.active { background: linear-gradient(to bottom, #3b82f6, #00f0ff); box-shadow: 0 0 10px rgba(0, 240, 255, 0.5); }

    .star-icon { color: #334155; font-size: 0.7rem; transition: color 0.3s; }
    .star-icon.filled { color: #ffb800; filter: drop-shadow(0 0 5px rgba(255, 184, 0, 0.8)); }

    #splash-screen {
      background: linear-gradient(180deg, #050511 0%, #0a0b1a 100%);
      z-index: 100;
    }
    
    .powerup-btn {
      transition: all 0.2s ease-in-out;
    }
    .powerup-btn:active {
      transform: scale(0.9) translateY(4px);
    }
    .powerup-active-glow {
      box-shadow: 0 0 20px var(--glow-color);
      border-color: var(--glow-color);
    }
  </style>
</head>
<body class="flex flex-col min-h-screen pb-32">
  
  <div class="bg-orb-1 animate-blob"></div>
  <div class="bg-orb-2 animate-blob animation-delay-2000"></div>

  <div id="loading-overlay" class="bg-[#050511] flex flex-col items-center justify-center z-[100000] fixed inset-0 transition-opacity duration-500">
    <div class="relative w-24 h-24 mb-8">
      <div class="absolute inset-0 rounded-full border-t-4 border-crypto-glow animate-[spin_1s_linear_infinite] shadow-[0_0_20px_rgba(0,240,255,0.5)]"></div>
      <div class="absolute inset-3 rounded-full border-b-4 border-blue-500 animate-[spin_1.5s_linear_infinite_reverse]"></div>
      <div class="absolute inset-0 flex items-center justify-center">
        <i class="fa-brands fa-space-awesome text-crypto-glow text-3xl animate-pulse"></i>
      </div>
    </div>
    <h2 class="text-white font-black tracking-[0.25em] text-2xl uppercase bg-clip-text text-transparent bg-gradient-to-r from-crypto-glow via-blue-400 to-indigo-500 mb-2 drop-shadow-lg">Point Play</h2>
    <div class="flex gap-1 mt-2">
      <div class="w-1.5 h-1.5 bg-crypto-glow rounded-full animate-bounce"></div>
      <div class="w-1.5 h-1.5 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 0.1s"></div>
      <div class="w-1.5 h-1.5 bg-indigo-500 rounded-full animate-bounce" style="animation-delay: 0.2s"></div>
    </div>
    <p id="time-sync-status" class="text-[10px] text-blue-400 font-bold tracking-widest mt-6 animate-pulse uppercase">Server Connection...</p>
  </div>

  <div id="toast-container" class="glass-card rounded-2xl p-4 flex items-center gap-4">
    <div id="toast-icon" class="w-12 h-12 rounded-full flex shrink-0 items-center justify-center text-xl shadow-inner">
      <i class="fa-solid fa-bell"></i>
    </div>
    <div class="flex-1">
      <h4 id="toast-title" class="text-sm font-black text-white tracking-wide">Notification</h4>
      <p id="toast-message" class="text-xs text-slate-300 mt-0.5 leading-tight">Message goes here</p>
    </div>
  </div>

  <!-- Info Modal for Referrals -->
  <div id="ref-info-modal" class="fixed inset-0 bg-[#050511]/95 backdrop-blur-md z-[1000] hidden flex-col items-center justify-center p-6">
    <div class="glass-card rounded-3xl p-6 w-full max-w-sm border border-blue-500/30 text-center relative">
       <div class="w-16 h-16 bg-blue-500/20 rounded-full flex items-center justify-center mx-auto mb-4 border border-blue-400/50">
          <i class="fa-solid fa-users text-2xl text-crypto-glow"></i>
       </div>
       <h2 class="text-xl font-black text-white uppercase tracking-widest mb-4">Referral Requirements</h2>
       <ul class="text-left text-sm text-slate-300 space-y-3 mb-6 font-medium">
          <li class="flex gap-3"><i class="fa-solid fa-check text-emerald-400 mt-1"></i> Watch 25 ads.</li>
          <li class="flex gap-3"><i class="fa-solid fa-check text-emerald-400 mt-1"></i> Complete 5 tasks.</li>
          <li class="flex gap-3"><i class="fa-solid fa-clock text-blue-400 mt-1"></i> There is no deadline. The referral remains pending until all requirements are completed.</li>
          <li class="flex gap-3"><i class="fa-solid fa-gift text-purple-400 mt-1"></i> Once requirements are completed, the referral becomes approved and you receive the reward (+250 XP & $0.025).</li>
       </ul>
       <button onclick="document.getElementById('ref-info-modal').classList.add('hidden')" class="w-full py-3.5 bg-gradient-to-r from-blue-600 to-cyan-500 text-white font-black rounded-xl uppercase tracking-wider shadow-[0_0_20px_rgba(0,240,255,0.4)] active:scale-95 transition-all">Understood</button>
    </div>
  </div>

  <header id="main-header" class="fixed top-0 left-0 right-0 z-50 w-full p-4 glass-card rounded-b-3xl border-b-0 shadow-lg transition-transform duration-300">
    <div class="flex justify-between items-center max-w-md mx-auto">
      <div class="flex items-center gap-3">
        <div class="relative w-11 h-11 rounded-full p-[2px] bg-gradient-to-tr from-blue-600 via-crypto-glow to-indigo-500 shadow-[0_0_15px_rgba(0,240,255,0.3)]">
          <img id="user-photo" src="https://via.placeholder.com/150/0a0b1a/00f0ff?text=PP" alt="Profile" class="w-full h-full rounded-full object-cover border-2 border-[#050511]">
        </div>
        <div class="flex flex-col">
          <span id="user-name" class="font-bold text-white text-sm tracking-wide">Loading...</span>
          <div class="flex items-center gap-1">
            <span class="w-2 h-2 rounded-full bg-emerald-400 shadow-[0_0_8px_#34d399] animate-pulse"></span>
            <span class="text-[10px] text-slate-400 uppercase font-bold tracking-wider">Online</span>
          </div>
        </div>
      </div>
      <div class="flex flex-col items-end gap-1.5">
        <div class="glass-button px-3 py-1.5 rounded-xl flex items-center gap-2">
          <i class="fa-solid fa-bolt text-crypto-glow text-xs drop-shadow-[0_0_5px_#00f0ff]"></i>
          <span id="user-xp" class="text-white font-black text-sm tracking-wider">0 <span class="text-[10px] text-crypto-glow">XP</span></span>
        </div>
        <div class="bg-emerald-900/40 border border-emerald-500/30 px-3 py-1 rounded-xl flex items-center gap-2 shadow-[0_0_10px_rgba(16,185,129,0.1)inset]">
          <i class="fa-solid fa-dollar-sign text-emerald-400 text-[10px]"></i>
          <span id="user-usd" class="text-emerald-400 font-black text-xs tracking-wider">0.00</span>
        </div>
      </div>
    </div>
  </header>

  <main class="flex-1 max-w-md w-full mx-auto p-4 pt-24 relative" id="app-content">
    
    <div id="view-home" class="view-section fade-in space-y-6">
      <div class="relative glass-card rounded-[2rem] p-6 text-center border-t border-t-blue-400/20 overflow-hidden flex flex-col items-center justify-center min-h-[240px]">
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-48 h-48 bg-blue-500/20 rounded-full filter blur-[40px] pointer-events-none animate-pulse-fast"></div>
        <div class="relative z-10 flex flex-col items-center">
          <div class="w-14 h-14 rounded-full bg-blue-900/40 border border-blue-400/30 flex items-center justify-center mb-3 shadow-[0_0_20px_rgba(59,130,246,0.3)]">
             <i class="fa-solid fa-gem text-2xl text-crypto-glow drop-shadow-[0_0_10px_rgba(0,240,255,0.8)]"></i>
          </div>
          <p class="text-[10px] font-black text-blue-400 uppercase tracking-[0.3em] mb-1 opacity-80">Balance</p>
          <h1 class="text-5xl font-black text-transparent bg-clip-text bg-gradient-to-b from-white via-cyan-100 to-blue-400 tracking-tighter drop-shadow-2xl" id="main-xp-display">0 XP</h1>
        </div>
        <div class="w-full mt-6 grid grid-cols-2 gap-3">
          <div class="bg-[#050511]/50 border border-slate-700/50 p-3 rounded-2xl flex items-center gap-3 backdrop-blur-md">
            <div class="bg-blue-500/10 p-2.5 rounded-xl border border-blue-500/20"><i class="fa-solid fa-clapperboard text-blue-400"></i></div>
            <div class="text-left">
              <p class="text-[9px] text-slate-500 uppercase font-bold tracking-wider">Daily Limit</p>
              <p class="text-sm font-black text-white"><span id="ads-watched" class="text-blue-400">0</span> / 30</p>
            </div>
          </div>
          <div class="bg-[#050511]/50 border border-slate-700/50 p-3 rounded-2xl flex items-center gap-3 backdrop-blur-md">
            <div class="bg-amber-500/10 p-2.5 rounded-xl border border-amber-500/20"><i class="fa-solid fa-fire-flame-curved text-amber-400"></i></div>
            <div class="text-left">
              <p class="text-[9px] text-slate-500 uppercase font-bold tracking-wider">Streak</p>
              <p class="text-sm font-black text-white"><span id="streak-days" class="text-amber-400">1</span> <span>Days</span></p>
            </div>
          </div>
        </div>
      </div>

      <button onclick="watchAd()" id="watch-ad-btn" class="w-full py-4 rounded-2xl text-white font-black text-sm tracking-[0.15em] uppercase flex items-center justify-center gap-3 shadow-[0_10px_30px_rgba(59,130,246,0.3)] btn-3d relative overflow-hidden group">
        <div class="absolute inset-0 bg-gradient-to-r from-transparent via-white/20 to-transparent -translate-x-full group-hover:animate-[shimmer_1.5s_infinite]"></div>
        <i class="fa-solid fa-play bg-white/20 p-2 rounded-full text-[10px]"></i> 
        <span><span>Watch Ad</span> <span class="text-cyan-200">+10 XP & $0.0015</span></span>
      </button>

      <div class="glass-card rounded-xl p-3 flex justify-between items-center">
        <div class="flex items-center gap-2 text-slate-400 text-xs">
          <i class="fa-solid fa-rotate text-blue-400"></i> <span>Resets in:</span>
        </div>
        <span class="text-white font-mono font-bold tracking-widest" id="reset-timer">--:--:--</span>
      </div>
    </div>

    <div id="view-games" class="view-section hidden fade-in space-y-4 pb-4">
      <div id="game-menu-container">
        <div class="text-center pt-2 mb-4">
          <h2 class="text-3xl font-black text-white tracking-tight drop-shadow-lg">Games</h2>
          <p class="text-[10px] text-blue-400 mt-1 uppercase tracking-widest font-bold bg-blue-500/10 inline-block px-3 py-1 rounded-full border border-blue-500/20">Point Play Balloon Blast</p>
        </div>

        <div class="glass-card rounded-3xl p-6 relative overflow-hidden flex flex-col items-center min-h-[400px]">
          <p class="text-xs text-slate-400 font-bold uppercase tracking-widest mb-6">Daily Levels Progress</p>
          <div class="relative w-full max-w-[250px] mx-auto py-4" id="level-map"></div>
        </div>
      </div>

      <div id="game-play-container" class="hidden flex flex-col gap-3">
        <div class="glass-card p-3 rounded-2xl flex justify-between items-center border border-blue-500/30 shrink-0 shadow-[0_4px_20px_rgba(0,0,0,0.5)]">
          <button onclick="exitGame()" class="w-10 h-10 rounded-xl bg-slate-800/50 flex items-center justify-center text-slate-400 hover:text-white active:scale-95 transition-all">
            <i class="fa-solid fa-arrow-left"></i>
          </button>
          <div class="text-center">
            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest"><span>Level</span> <span id="ingame-level-display">1</span></p>
            <p class="text-lg font-black text-white drop-shadow-[0_0_5px_rgba(255,255,255,0.5)]" id="ingame-score">0</p>
          </div>
          <div class="flex gap-1 bg-slate-900/50 p-2 rounded-xl border border-slate-700/50" id="ingame-stars">
            <i class="fa-solid fa-star text-slate-700 text-xs"></i>
            <i class="fa-solid fa-star text-slate-700 text-xs"></i>
            <i class="fa-solid fa-star text-slate-700 text-xs"></i>
          </div>
        </div>

        <div id="game-canvas-container">
          <div id="splash-screen" class="absolute inset-0 flex flex-col items-center justify-center text-center transition-opacity duration-500">
            <div class="w-20 h-20 bg-blue-500/20 rounded-2xl flex items-center justify-center mb-4 border border-blue-400/50 shadow-[0_0_30px_rgba(59,130,246,0.5)]">
              <i class="fa-solid fa-gamepad text-4xl text-crypto-glow"></i>
            </div>
            <h1 class="text-2xl font-black text-white tracking-widest uppercase mb-1">Point Play</h1>
            <h2 class="text-lg font-bold text-blue-400 tracking-wider">Studios</h2>
          </div>
          
          <canvas id="balloon-canvas"></canvas>

          <div id="game-over-modal" class="absolute inset-0 bg-[#050511]/95 backdrop-blur-md z-20 flex flex-col items-center justify-center p-6 hidden fade-in">
            <h2 id="end-title" class="text-4xl font-black text-white mb-2 drop-shadow-[0_0_15px_#00f0ff]">Level Clear!</h2>
            <div class="flex gap-2 mb-4" id="end-stars"></div>
            <p id="end-reward" class="text-lg font-bold text-emerald-400 mb-8 bg-emerald-500/10 px-6 py-3 rounded-xl border border-emerald-500/30 shadow-[0_0_20px_rgba(16,185,129,0.2)]">+0 XP</p>
            <div class="flex gap-4 w-full max-w-[250px]">
              <button onclick="exitGame()" class="flex-1 py-3.5 rounded-xl bg-slate-800 text-white font-black uppercase text-xs tracking-wider shadow-lg active:scale-95 transition-all border border-slate-600" id="btn-back">Back</button>
              <button id="end-next-btn" onclick="nextLevel()" class="flex-1 py-3.5 rounded-xl bg-gradient-to-r from-blue-600 to-cyan-500 text-white font-black uppercase text-xs tracking-wider shadow-[0_0_20px_rgba(0,240,255,0.4)] active:scale-95 transition-all">Next</button>
            </div>
          </div>
        </div>

        <div class="glass-card p-3 rounded-2xl flex justify-center gap-10 items-center shrink-0 border border-slate-700/50">
          <div class="flex flex-col items-center gap-1.5 group relative">
            <button onclick="activatePowerup('bomb')" class="powerup-btn w-12 h-12 rounded-2xl bg-slate-800/80 border-2 border-slate-600 flex items-center justify-center text-orange-400 relative shadow-[0_4px_15px_rgba(0,0,0,0.5)]" id="btn-power-bomb" style="--glow-color: rgba(249,115,22,0.6)">
              <i class="fa-solid fa-bomb text-xl drop-shadow-[0_0_5px_rgba(249,115,22,0.8)]"></i>
              <span class="absolute -top-2 -right-2 bg-blue-600 text-white text-[10px] font-black w-6 h-6 rounded-full flex items-center justify-center border-2 border-[#050511] shadow-lg" id="count-bomb">1</span>
            </button>
            <span class="text-[9px] uppercase font-bold text-slate-400 tracking-wider">Bomb</span>
          </div>

          <div class="flex flex-col items-center gap-1.5 group relative">
            <button onclick="activatePowerup('rocket')" class="powerup-btn w-12 h-12 rounded-2xl bg-slate-800/80 border-2 border-slate-600 flex items-center justify-center text-red-400 relative shadow-[0_4px_15px_rgba(0,0,0,0.5)]" id="btn-power-rocket" style="--glow-color: rgba(239,68,68,0.6)">
              <i class="fa-solid fa-rocket text-xl drop-shadow-[0_0_5px_rgba(239,68,68,0.8)]"></i>
              <span class="absolute -top-2 -right-2 bg-blue-600 text-white text-[10px] font-black w-6 h-6 rounded-full flex items-center justify-center border-2 border-[#050511] shadow-lg" id="count-rocket">1</span>
            </button>
            <span class="text-[9px] uppercase font-bold text-slate-400 tracking-wider">Rocket</span>
          </div>
        </div>

      </div>
    </div>

    <div id="view-tasks" class="view-section hidden fade-in space-y-6 pb-4">
      <div class="text-center pt-2">
        <h2 class="text-3xl font-black text-white tracking-tight drop-shadow-lg">Tasks</h2>
        <p class="text-xs text-crypto-glow mt-1 uppercase tracking-widest font-bold">Complete to Earn XP</p>
      </div>
      
      <div class="glass-card rounded-3xl p-5 relative overflow-hidden border-t-2 border-t-blue-500/30">
        <div class="absolute -right-10 -top-10 w-32 h-32 bg-blue-600/20 rounded-full blur-2xl"></div>
        <h3 class="text-xs font-black text-white uppercase tracking-widest mb-5 flex items-center gap-2">
          <i class="fa-solid fa-calendar-check text-blue-400 text-lg"></i> <span>7-Day Streak</span>
        </h3>
        <div class="relative flex justify-between items-center" id="streak-tracker-container"></div>
      </div>

      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-4 mb-3">Daily Missions</h3>
        <div id="missions-container" class="space-y-3"></div>
      </div>
    </div>

    <!-- NEW REFERRALS VIEW -->
    <div id="view-referrals" class="view-section hidden fade-in space-y-6 pb-4">
      <div class="text-center pt-2">
        <h2 class="text-3xl font-black text-white tracking-tight drop-shadow-lg">Referrals</h2>
        <p class="text-xs text-crypto-glow mt-1 uppercase tracking-widest font-bold">Invite friends, earn together</p>
      </div>

      <div class="grid grid-cols-3 gap-2">
         <div class="glass-card p-3 rounded-2xl text-center border-t-2 border-blue-500/30">
            <p class="text-[9px] text-slate-400 uppercase font-black tracking-widest mb-1">Total</p>
            <p id="ref-total" class="text-lg font-black text-white">0</p>
         </div>
         <div class="glass-card p-3 rounded-2xl text-center border-t-2 border-amber-500/30">
            <p class="text-[9px] text-slate-400 uppercase font-black tracking-widest mb-1">Pending</p>
            <p id="ref-pending" class="text-lg font-black text-amber-400">0</p>
         </div>
         <div class="glass-card p-3 rounded-2xl text-center border-t-2 border-emerald-500/30">
            <p class="text-[9px] text-slate-400 uppercase font-black tracking-widest mb-1">Approved</p>
            <p id="ref-approved" class="text-lg font-black text-emerald-400">0</p>
         </div>
      </div>

      <div class="glass-card rounded-2xl p-4 text-center">
         <p class="text-[10px] font-bold text-slate-400 uppercase">Your Referral Link</p>
         <div class="bg-[#050511] p-3 rounded-xl mt-2 text-xs font-mono text-blue-400 break-all border border-slate-800 shadow-inner" id="ref-link-display">Loading...</div>
         <div class="flex gap-2 mt-3">
            <button onclick="copyRefLink()" class="flex-1 bg-slate-800 border border-slate-600 text-white py-3 rounded-xl text-xs font-black uppercase tracking-wider active:scale-95 transition-all"><i class="fa-solid fa-copy mr-1"></i> Copy Link</button>
            <button onclick="shareRefLink()" class="flex-1 bg-gradient-to-r from-blue-600 to-cyan-500 text-white py-3 rounded-xl text-xs font-black uppercase tracking-wider shadow-[0_0_15px_rgba(0,240,255,0.3)] active:scale-95 transition-all"><i class="fa-solid fa-paper-plane mr-1"></i> Share</button>
         </div>
      </div>

      <div>
         <div class="flex justify-between items-center px-2 mb-3">
            <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em]">My Referrals</h3>
            <button onclick="document.getElementById('ref-info-modal').classList.remove('hidden')" class="text-slate-400 hover:text-white transition-colors"><i class="fa-solid fa-circle-info text-lg drop-shadow-[0_0_5px_rgba(255,255,255,0.2)]"></i></button>
         </div>
         <div id="referrals-list-container" class="space-y-2"></div>
      </div>

      <div>
         <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] px-2 mb-3">Referral Reward History</h3>
         <div id="referral-history-container" class="space-y-2"></div>
      </div>
    </div>

    <div id="view-boxes" class="view-section hidden fade-in space-y-5 pb-4">
      <div class="text-center pt-2 mb-6">
        <h2 class="text-3xl font-black text-white tracking-tight drop-shadow-lg">Boxes</h2>
        <p class="text-xs text-amber-400 mt-1 uppercase tracking-widest font-bold">Try Your Luck, Win Dollars</p>
      </div>
      
      <div class="box-bronze glass-card rounded-[1.5rem] p-5 relative overflow-hidden flex justify-between items-center transition-transform hover:scale-[1.02]">
        <div class="absolute -right-4 top-1/2 -translate-y-1/2 w-32 h-32 bg-crypto-bronze/10 rounded-full blur-2xl"></div>
        <div class="flex items-center gap-4 relative z-10">
          <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-orange-900 to-[#050511] border border-crypto-bronze flex items-center justify-center shadow-[0_0_15px_rgba(205,127,50,0.3)]">
            <i class="fa-solid fa-box text-2xl text-crypto-bronze"></i>
          </div>
          <div>
            <h3 class="text-lg font-black text-white tracking-wide">Bronze Box</h3>
            <div class="flex flex-col mt-0.5">
              <span class="text-[10px] text-slate-400 font-bold tracking-wider uppercase"><i class="fa-solid fa-bolt text-crypto-glow mr-1"></i> 10,000 XP</span>
              <span class="text-[11px] text-emerald-400 font-bold mt-0.5"><span>Maximum:</span> $1.00</span>
            </div>
          </div>
        </div>
        <button onclick="openBox('bronze')" class="relative z-10 bg-gradient-to-b from-orange-600 to-orange-800 text-white shadow-[0_4px_15px_rgba(205,127,50,0.4)] hover:brightness-110 active:scale-95 transition-all px-4 py-2 rounded-xl text-xs font-black uppercase tracking-wider">Open</button>
      </div>

      <div class="box-silver glass-card rounded-[1.5rem] p-5 relative overflow-hidden flex justify-between items-center transition-transform hover:scale-[1.02]">
        <div class="absolute -right-4 top-1/2 -translate-y-1/2 w-32 h-32 bg-crypto-silver/10 rounded-full blur-2xl"></div>
        <div class="flex items-center gap-4 relative z-10">
          <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-slate-600 to-[#050511] border border-crypto-silver flex items-center justify-center shadow-[0_0_15px_rgba(226,232,240,0.2)]">
            <i class="fa-solid fa-box-open text-2xl text-crypto-silver"></i>
          </div>
          <div>
            <h3 class="text-lg font-black text-white tracking-wide">Silver Box</h3>
            <div class="flex flex-col mt-0.5">
              <span class="text-[10px] text-slate-400 font-bold tracking-wider uppercase"><i class="fa-solid fa-bolt text-crypto-glow mr-1"></i> 50,000 XP</span>
              <span class="text-[11px] text-emerald-400 font-bold mt-0.5"><span>Maximum:</span> $7.00</span>
            </div>
          </div>
        </div>
        <button onclick="openBox('silver')" class="relative z-10 bg-gradient-to-b from-slate-400 to-slate-600 text-crypto-dark shadow-[0_4px_15px_rgba(226,232,240,0.3)] hover:brightness-110 active:scale-95 transition-all px-4 py-2 rounded-xl text-xs font-black uppercase tracking-wider">Open</button>
      </div>

      <div class="box-gold glass-card rounded-[1.5rem] p-5 relative overflow-hidden flex justify-between items-center border border-crypto-gold shadow-[0_0_20px_rgba(255,184,0,0.15)] transition-transform hover:scale-[1.02]">
        <div class="absolute -right-4 top-1/2 -translate-y-1/2 w-32 h-32 bg-crypto-gold/20 rounded-full blur-xl animate-pulse"></div>
        <div class="flex items-center gap-4 relative z-10">
          <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-500 to-[#050511] border border-crypto-gold flex items-center justify-center shadow-[0_0_25px_rgba(255,184,0,0.5)]">
            <i class="fa-solid fa-gem text-2xl text-crypto-gold drop-shadow-[0_0_10px_#ffb800]"></i>
          </div>
          <div>
            <h3 class="text-lg font-black text-crypto-gold tracking-wide drop-shadow-[0_0_5px_rgba(255,184,0,0.5)]">Gold Box</h3>
            <div class="flex flex-col mt-0.5">
              <span class="text-[10px] text-slate-400 font-bold tracking-wider uppercase"><i class="fa-solid fa-bolt text-crypto-glow mr-1"></i> 100,000 XP</span>
              <span class="text-[11px] text-emerald-400 font-bold mt-0.5"><span>Maximum:</span> $15.00</span>
            </div>
          </div>
        </div>
        <button onclick="openBox('gold')" class="relative z-10 bg-gradient-to-b from-yellow-400 to-amber-600 text-crypto-dark shadow-[0_4px_20px_rgba(255,184,0,0.6)] hover:brightness-110 active:scale-95 transition-all px-4 py-2 rounded-xl text-xs font-black uppercase tracking-wider">Open</button>
      </div>
    </div>

    <div id="view-withdraw" class="view-section hidden fade-in space-y-6">
      <div class="glass-card rounded-[2rem] p-6 text-center border-t-2 border-emerald-500/40 bg-gradient-to-b from-emerald-900/30 to-[#050511]">
        <div class="w-14 h-14 mx-auto bg-emerald-500/10 rounded-full flex items-center justify-center mb-3 border border-emerald-500/30 shadow-[0_0_20px_rgba(16,185,129,0.2)]">
          <i class="fa-solid fa-wallet text-xl text-emerald-400"></i>
        </div>
        <p class="text-[10px] font-black text-emerald-400 uppercase tracking-[0.2em] mb-1 opacity-80">Withdrawal Balance</p>
        <h1 class="text-5xl font-black text-white tracking-tighter mb-3">$<span id="withdraw-balance-display">0.00</span></h1>
        <div class="inline-block bg-[#050511]/80 backdrop-blur-md px-3 py-1.5 rounded-full border border-slate-700/50">
          <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest"><i class="fa-solid fa-circle-info text-blue-400 mr-1"></i> <span>Minimum:</span> $10</p>
        </div>
      </div>

      <div class="glass-card rounded-[1.5rem] p-5 space-y-4 border-slate-800">
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] mb-2 ml-1">TON Wallet Address</label>
          <div class="relative group">
            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
              <img src="https://cryptologos.cc/logos/toncoin-ton-logo.png" class="w-5 h-5 opacity-70 group-focus-within:opacity-100 transition-opacity" alt="TON">
            </div>
            <input type="text" id="wallet-address" placeholder="UQ..." class="w-full bg-[#050511]/50 border-2 border-slate-700/50 rounded-xl py-3.5 pl-12 pr-4 text-sm font-medium text-white focus:outline-none focus:border-blue-500 transition-colors placeholder-slate-600 shadow-inner">
          </div>
        </div>
        
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] mb-2 ml-1">Amount (USD)</label>
          <div class="relative group">
            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
              <i class="fa-solid fa-dollar-sign text-slate-500 group-focus-within:text-emerald-400 transition-colors text-lg"></i>
            </div>
            <input type="number" id="withdraw-amount" placeholder="10" min="10" step="0.5" class="w-full bg-[#050511]/50 border-2 border-slate-700/50 rounded-xl py-3.5 pl-12 pr-4 text-sm font-bold text-emerald-400 focus:outline-none focus:border-emerald-500 transition-colors placeholder-slate-600 shadow-inner">
          </div>
        </div>

        <button onclick="requestWithdrawal()" id="withdraw-btn" class="w-full py-3.5 mt-2 bg-gradient-to-r from-emerald-600 to-teal-500 hover:brightness-110 active:scale-95 transition-all text-white font-black rounded-xl text-sm uppercase tracking-[0.15em] flex items-center justify-center gap-2 shadow-[0_10px_20px_rgba(16,185,129,0.3)]">
          <i class="fa-solid fa-money-bill-transfer"></i> <span>Request Withdrawal</span>
        </button>
      </div>

      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-4 mb-3">History</h3>
        <div id="withdraw-history-container" class="space-y-3"></div>
      </div>
    </div>

    <div id="view-profile" class="view-section hidden fade-in space-y-6">
      <div class="glass-card rounded-[2.5rem] p-1 text-center relative overflow-hidden border border-slate-700/50">
        <div class="h-32 bg-gradient-to-r from-blue-900 via-indigo-900 to-purple-900 rounded-t-[2.3rem] relative overflow-hidden">
           <div class="absolute inset-0 bg-[url('https://www.transparenttextures.com/patterns/cubes.png')] opacity-20"></div>
           <div class="absolute -bottom-10 left-1/2 -translate-x-1/2 w-full h-20 bg-gradient-to-t from-[#0a0b1a] to-transparent"></div>
        </div>
        
        <div class="px-6 pb-8 -mt-16 relative z-10">
          <div class="relative w-24 h-24 mx-auto rounded-full bg-gradient-to-b from-crypto-glow to-blue-600 p-1 mb-3 shadow-[0_0_30px_rgba(0,240,255,0.4)]">
             <img id="profile-photo-large" src="https://via.placeholder.com/150" class="w-full h-full rounded-full border-4 border-[#0a0b1a] object-cover">
             <div class="absolute -bottom-2 -right-2 bg-gradient-to-br from-[#0a0b1a] to-blue-900 border-2 border-crypto-glow text-white w-8 h-8 rounded-full flex items-center justify-center font-black text-xs shadow-[0_0_15px_#00f0ff]" id="profile-badge-level">1</div>
          </div>
          
          <h2 id="profile-name" class="text-2xl font-black text-white tracking-tight">User</h2>
          <p id="profile-tgid" class="text-[10px] text-blue-400 font-mono mt-1 tracking-widest bg-blue-500/10 inline-block px-3 py-1 rounded-full border border-blue-500/20">ID: 00000000</p>
          
          <div class="mt-6">
            <div class="flex justify-between items-end mb-1.5 px-1">
               <span class="text-[10px] font-black text-crypto-glow uppercase tracking-wider">Level Progress</span>
               <span class="text-[10px] font-bold text-slate-400" id="profile-current-xp-text">0 XP</span>
            </div>
            <div class="w-full bg-[#050511] rounded-full h-3 border border-slate-700/50 overflow-hidden relative shadow-inner p-0.5">
               <div id="profile-xp-progress" class="bg-gradient-to-r from-blue-600 via-crypto-glow to-indigo-400 h-full rounded-full transition-all duration-1000 w-[0%] relative">
                 <div class="absolute inset-0 bg-white/20 w-full h-full animate-[shimmer_2s_infinite]"></div>
               </div>
            </div>
            <div class="text-right mt-1 px-1">
              <span class="text-[9px] font-bold text-slate-500 uppercase"><span>Target:</span> <span id="profile-next-xp-text" class="text-blue-400">250 XP</span></span>
            </div>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div class="glass-card p-5 rounded-2xl text-center border-t-2 border-t-blue-500/30">
          <div class="w-10 h-10 mx-auto bg-blue-500/10 border border-blue-500/20 text-blue-400 rounded-xl flex items-center justify-center mb-2 text-lg shadow-[0_0_15px_rgba(59,130,246,0.2)inset]"><i class="fa-solid fa-chart-line"></i></div>
          <p class="text-[9px] text-slate-400 uppercase font-black tracking-[0.15em] mb-1">Total Earned</p>
          <p id="profile-total-xp" class="text-xl font-black text-white">0</p>
        </div>
        <div class="glass-card p-5 rounded-2xl text-center border-t-2 border-t-amber-500/30">
          <div class="w-10 h-10 mx-auto bg-amber-500/10 border border-amber-500/20 text-amber-400 rounded-xl flex items-center justify-center mb-2 text-lg shadow-[0_0_15px_rgba(245,158,11,0.2)inset]"><i class="fa-solid fa-box-open"></i></div>
          <p class="text-[9px] text-slate-400 uppercase font-black tracking-[0.15em] mb-1">Boxes Opened</p>
          <p id="profile-boxes" class="text-xl font-black text-white">0</p>
        </div>
      </div>
    </div>

  </main>

  <nav id="bottom-nav" class="fixed bottom-6 left-1/2 -translate-x-1/2 w-[calc(100%-1rem)] max-w-[420px] glass-card rounded-2xl pb-safe z-50 shadow-[0_20px_40px_rgba(0,0,0,0.8)] border border-slate-700/50 backdrop-blur-xl">
    <div class="flex justify-between items-center px-1 py-2.5 relative">
      <button onclick="switchTab('home')" class="nav-btn nav-active text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-300 relative group" data-target="home">
        <i class="fa-solid fa-house text-lg transition-transform group-active:scale-90"></i>
        <span class="text-[7px] font-black uppercase tracking-widest">Home</span>
      </button>
      <button onclick="switchTab('games')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-300 relative group" data-target="games">
        <i class="fa-solid fa-gamepad text-lg transition-transform group-active:scale-90"></i>
        <span class="text-[7px] font-black uppercase tracking-widest">Games</span>
      </button>
      <button onclick="switchTab('tasks')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-300 relative group" data-target="tasks">
        <i class="fa-solid fa-list-check text-lg transition-transform group-active:scale-90"></i>
        <span class="text-[7px] font-black uppercase tracking-widest">Tasks</span>
      </button>
      <button onclick="switchTab('referrals')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-300 relative group" data-target="referrals">
        <i class="fa-solid fa-users text-lg transition-transform group-active:scale-90"></i>
        <span class="text-[7px] font-black uppercase tracking-widest">Referrals</span>
      </button>
      <button onclick="switchTab('withdraw')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-300 relative group" data-target="withdraw">
        <i class="fa-solid fa-wallet text-lg transition-transform group-active:scale-90"></i>
        <span class="text-[7px] font-black uppercase tracking-widest">Withdraw</span>
      </button>
      <button onclick="switchTab('profile')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-300 relative group" data-target="profile">
        <i class="fa-solid fa-user-astronaut text-lg transition-transform group-active:scale-90"></i>
        <span class="text-[7px] font-black uppercase tracking-widest">Profile</span>
      </button>
    </div>
  </nav>

  <script>
    const tg = window.Telegram.WebApp;
    tg.expand(); 
    tg.ready();

    tg.setHeaderColor('#0a0b1a');
    tg.setBackgroundColor('#050511');

    let userState = null;
    let missionsState = null;
    let gameState = null;

    let accurateTimeOffset = null;
    let isTimeSynced = false;
    let headerTimeout; 
    let currentLevelDisplay = 1;

    const bottomNavElement = document.getElementById('bottom-nav');
    let maxViewportHeight = window.visualViewport ? window.visualViewport.height : window.innerHeight;

    function handleKeyboardState() {
      if (document.body.classList.contains('in-game')) return; 
      let currentHeight = window.visualViewport ? window.visualViewport.height : window.innerHeight;
      if (currentHeight > maxViewportHeight) maxViewportHeight = currentHeight;
      if (maxViewportHeight - currentHeight > 100) {
          bottomNavElement.classList.add('nav-hidden');
      } else {
          bottomNavElement.classList.remove('nav-hidden');
      }
    }

    if (window.visualViewport) window.visualViewport.addEventListener('resize', handleKeyboardState);
    window.addEventListener('resize', handleKeyboardState);
    if (tg.onEvent) tg.onEvent('viewportChanged', handleKeyboardState);

    async function apiCall(action, data = {}) {
        const payload = {
            action: action,
            tgId: tg.initDataUnsafe?.user?.id || '123456789',
            tgData: tg.initDataUnsafe?.user || {first_name: 'Point Play User', username: 'pointplay'},
            startapp: tg.initDataUnsafe?.start_param || null,
            ...data
        };
        try {
            const response = await fetch('index.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            return await response.json();
        } catch (e) {
            console.error('API Error:', e);
            return null;
        }
    }

    async function fetchServerTime() {
      const statusText = document.getElementById('time-sync-status');
      try {
        const res = await fetch('https://worldtimeapi.org/api/timezone/Etc/UTC');
        const data = await res.json();
        const serverTime = new Date(data.utc_datetime).getTime();
        accurateTimeOffset = serverTime - performance.now();
        isTimeSynced = true;
        if(statusText) statusText.innerText = "Synced!";
        return true;
      } catch (e) {
        if(statusText) statusText.innerText = "Connection Failed. Retrying...";
        setTimeout(fetchServerTime, 5000); 
        return false;
      }
    }

    function getNow() {
      if (!isTimeSynced) return null; 
      return new Date(accurateTimeOffset + performance.now());
    }

    function showToast(title, message, type = 'info') {
      const toast = document.getElementById('toast-container');
      const icon = document.getElementById('toast-icon');
      
      document.getElementById('toast-title').innerText = title;
      document.getElementById('toast-message').innerText = message;
      
      let iconClass, iconHtml, borderStyle;
      if (type === 'success') {
        iconHtml = '<i class="fa-solid fa-check"></i>';
        iconClass = 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/50 shadow-[0_0_15px_rgba(16,185,129,0.3)]';
        borderStyle = '1px solid rgba(16, 185, 129, 0.4)';
      } else if (type === 'error') {
        iconHtml = '<i class="fa-solid fa-xmark"></i>';
        iconClass = 'bg-red-500/20 text-red-400 border border-red-500/50 shadow-[0_0_15px_rgba(239,68,68,0.3)]';
        borderStyle = '1px solid rgba(239, 68, 68, 0.4)';
      } else if (type === 'jackpot') {
        iconHtml = '<i class="fa-solid fa-sack-dollar animate-bounce"></i>';
        iconClass = 'bg-amber-500/20 text-amber-400 border border-amber-500/50 shadow-[0_0_20px_rgba(251,191,36,0.6)]';
        borderStyle = '1px solid rgba(251, 191, 36, 0.8)';
      } else {
        iconHtml = '<i class="fa-solid fa-bell animate-pulse"></i>';
        iconClass = 'bg-blue-500/20 text-crypto-glow border border-crypto-glow/50 shadow-[0_0_15px_rgba(0,240,255,0.3)]';
        borderStyle = '1px solid rgba(0, 240, 255, 0.4)';
      }

      icon.innerHTML = iconHtml;
      icon.className = `w-12 h-12 rounded-xl flex shrink-0 items-center justify-center text-xl ${iconClass}`;
      toast.style.border = borderStyle;

      toast.classList.add('toast-show');
      
      if (tg.HapticFeedback) {
        if (type === 'success' || type === 'jackpot') tg.HapticFeedback.notificationOccurred('success');
        else if (type === 'error') tg.HapticFeedback.notificationOccurred('error');
        else tg.HapticFeedback.notificationOccurred('warning');
      }

      setTimeout(() => { toast.classList.remove('toast-show'); }, type === 'jackpot' ? 5000 : 3000); 
    }

    function updateLocalState(data) {
        if(data && data.user) {
            userState = data.user;
            missionsState = data.missions;
            gameState = data.game;
            updateUI();
        }
    }

    function updateProfileProgress() {
      const xp = userState.totalXp;
      let nextLvlXp = 250;
      let prevLvlXp = 0;
      
      if (xp >= 20000) { nextLvlXp = 20000; prevLvlXp = 20000; }
      else if (xp >= 3000) { nextLvlXp = 20000; prevLvlXp = 3000; }
      else if (xp >= 1500) { nextLvlXp = 3000; prevLvlXp = 1500; }
      else if (xp >= 750) { nextLvlXp = 1500; prevLvlXp = 750; }
      else if (xp >= 250) { nextLvlXp = 750; prevLvlXp = 250; }

      let progress = 100;
      if (nextLvlXp !== prevLvlXp) {
        progress = ((xp - prevLvlXp) / (nextLvlXp - prevLvlXp)) * 100;
      }

      document.getElementById('profile-xp-progress').style.width = `${progress}%`;
      document.getElementById('profile-current-xp-text').innerText = `${xp.toLocaleString()} XP`;
      document.getElementById('profile-next-xp-text').innerText = (nextLvlXp === 20000 && xp >= 20000) ? 'MAX LVL' : `${nextLvlXp.toLocaleString()} XP`;
      document.getElementById('profile-badge-level').innerText = userState.level;
    }

    function updateUI() {
      if(!userState) return;
      document.getElementById('user-name').innerText = userState.firstName;
      document.getElementById('user-xp').innerHTML = `${userState.xp.toLocaleString()} <span class="text-[10px] text-crypto-glow font-bold">XP</span>`;
      document.getElementById('user-usd').innerText = userState.usdBalance.toFixed(4);
      
      if (userState.photoUrl) {
        document.getElementById('user-photo').src = userState.photoUrl;
        document.getElementById('profile-photo-large').src = userState.photoUrl;
      }

      document.getElementById('main-xp-display').innerText = `${userState.xp.toLocaleString()} XP`;
      document.getElementById('ads-watched').innerText = userState.adsWatchedToday;
      document.getElementById('streak-days').innerText = userState.streak;

      document.getElementById('withdraw-balance-display').innerText = userState.usdBalance.toFixed(2);
      renderWithdrawHistory();

      document.getElementById('profile-name').innerText = userState.firstName;
      document.getElementById('profile-tgid').innerText = `ID: ${userState.tgId}`;
      document.getElementById('profile-total-xp').innerText = userState.totalXp.toLocaleString();
      document.getElementById('profile-boxes').innerText = userState.boxesOpened;
      
      const refLink = `https://t.me/pointplayappbot?startapp=${userState.tgId}`;
      document.getElementById('ref-link-display').innerText = refLink;

      updateProfileProgress();
      renderMissions();
      renderStreakTracker();
      renderGameMap();
    }

    function renderStreakTracker() {
      const container = document.getElementById('streak-tracker-container');
      container.innerHTML = '';
      const streakRewards = [5, 10, 15, 20, 25, 30, 50]; 
      for (let i = 1; i <= 7; i++) {
        const isPast = i < userState.streak || (i === userState.streak && missionsState.dailyReward.claimed);
        const isToday = i === userState.streak && !missionsState.dailyReward.claimed;
        const reward = streakRewards[i-1];
        
        let styles = "bg-[#050511] border-slate-700 text-slate-600";
        let icon = `<span class="text-[9px] font-black">${reward}</span>`;
        let lineStyle = "bg-slate-800";
        
        if (isPast) {
          styles = "bg-emerald-500/20 border-emerald-500/50 text-emerald-400 shadow-[0_0_15px_rgba(16,185,129,0.3)]";
          icon = `<i class="fa-solid fa-check text-xs"></i>`;
          lineStyle = "bg-emerald-500/50 shadow-[0_0_5px_#34d399]";
        } else if (isToday) {
          styles = "bg-blue-600/30 border-crypto-glow shadow-[0_0_20px_rgba(0,240,255,0.5)] text-white";
        }

        container.innerHTML += `
          <div class="relative flex flex-col items-center gap-1.5 z-10 flex-1">
            <div class="w-9 h-9 rounded-xl border-2 flex items-center justify-center transition-all duration-300 ${styles} z-10 relative bg-[#0a0b1a]">
              ${icon}
            </div>
            <span class="text-[8px] font-black tracking-widest ${isToday ? 'text-crypto-glow drop-shadow-[0_0_5px_#00f0ff]' : 'text-slate-500'}">DAY ${i}</span>
            ${i < 7 ? `<div class="absolute top-4 left-[50%] w-full h-1 -z-0 ${lineStyle} rounded-full"></div>` : ''}
          </div>
        `;
      }
    }

    function renderMissions() {
      const container = document.getElementById('missions-container');
      container.innerHTML = '';
      
      const missionsList = [
        { key: 'dailyReward', label: `Daily Login`, icon: 'fa-gift', color: 'text-purple-400', bg: 'bg-purple-500/10 border-purple-500/20' },
        { key: 'watch5', label: 'Watch 5 Ads', icon: 'fa-video', color: 'text-blue-400', bg: 'bg-blue-500/10 border-blue-500/20' },
        { key: 'watch30', label: 'Watch 30 Ads', icon: 'fa-film', color: 'text-indigo-400', bg: 'bg-indigo-500/10 border-indigo-500/20' },
        { key: 'all', label: 'All Tasks', icon: 'fa-crown', color: 'text-amber-400', bg: 'bg-amber-500/10 border-amber-500/20' }
      ];

      missionsList.forEach(m => {
        const mission = missionsState[m.key];
        let isComplete = false;
        let progressText = '';
        
        if (mission.type === 'progress') {
            isComplete = userState.adsWatchedToday >= mission.target;
            progressText = `(${Math.min(userState.adsWatchedToday, mission.target)}/${mission.target})`;
        } else if (mission.type === 'boolean') {
            isComplete = mission.completed;
        } else if (mission.type === 'meta') {
            isComplete = ['dailyReward', 'watch5', 'watch30'].every(k => missionsState[k].claimed);
        }

        let btnHtml = '';
        if (mission.claimed) {
            btnHtml = `<span class="text-[10px] font-black bg-emerald-500/10 text-emerald-400 px-3 py-1.5 rounded-xl border border-emerald-500/30 flex items-center gap-1 shadow-[0_0_10px_rgba(16,185,129,0.1)inset]"><i class="fa-solid fa-check-double"></i> Claimed</span>`;
        } else if (isComplete) {
            btnHtml = `<button onclick="claimMission('${m.key}')" class="text-[10px] font-black bg-gradient-to-r from-blue-600 to-cyan-500 text-white px-4 py-1.5 rounded-xl shadow-[0_0_15px_rgba(0,240,255,0.4)] active:scale-95 transition-all uppercase tracking-wider">Claim</button>`;
        } else {
            btnHtml = `<span class="text-[10px] font-black bg-slate-800/50 text-slate-200 px-3 py-1.5 rounded-xl border border-slate-600 shadow-inner">+${mission.reward} XP</span>`;
        }

        container.innerHTML += `
          <div class="glass-card rounded-2xl p-3 flex justify-between items-center transition-transform hover:-translate-y-0.5 border border-slate-800">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl border ${m.bg} flex items-center justify-center">
                 <i class="fa-solid ${m.icon} ${m.color} text-lg"></i>
              </div>
              <div class="flex flex-col">
                <span class="text-xs font-black text-white tracking-wide">${m.label}</span>
                <span class="text-crypto-glow text-[10px] font-bold tracking-widest uppercase opacity-80 mt-0.5">${progressText}</span>
              </div>
            </div>
            ${btnHtml}
          </div>
        `;
      });
    }

    async function claimMission(key) {
        if(missionsState[key].claimed) return;
        const res = await apiCall('claim_mission', {key});
        if(res && res.success) {
            updateLocalState(res.data);
            showToast('Task Completed!', `You earned XP!`, 'success');
        }
    }

    function watchAd() {
      if (!isTimeSynced) {
        showToast('Error', 'Server time could not be verified. Please check your connection.', 'error');
        return;
      }
      
      if (userState.adsWatchedToday >= 30) {
        showToast('Limit', 'You have reached the daily limit of 30 ads. Come back tomorrow!', 'error');
        return;
      }

      const btn = document.getElementById('watch-ad-btn');
      const originalHTML = btn.innerHTML;
      btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> <span>Loading Ad...</span>`;
      btn.classList.add('opacity-80', 'pointer-events-none');

      if (window.Adsgram) {
        const AdController = window.Adsgram.init({ blockId: "int-35545" });
        
        AdController.show().then(async (result) => {
          const res = await apiCall('watch_ad');
          if(res && res.success) {
              updateLocalState(res.data);
              showToast('Reward Granted!', 'You successfully watched the ad and earned +10 XP and $0.0015.', 'success');
          }
          btn.innerHTML = originalHTML;
          btn.classList.remove('opacity-80', 'pointer-events-none');
        }).catch((error) => {
          btn.innerHTML = originalHTML;
          btn.classList.remove('opacity-80', 'pointer-events-none');
        });
      } else {
        showToast('Error', 'The ad system is currently not working.', 'error');
        btn.innerHTML = originalHTML;
        btn.classList.remove('opacity-80', 'pointer-events-none');
      }
    }

    async function openBox(type) {
      const boxCosts = { bronze: 10000, silver: 50000, gold: 100000 };
      const cost = boxCosts[type];
      if (userState.xp < cost) {
        showToast('Not enough XP', `You need ${cost.toLocaleString()} XP to open this box.`, 'error');
        return;
      }

      const res = await apiCall('open_box', {type});
      if(res && res.success) {
          updateLocalState(res.data);
          if (res.isJackpot) {
             showToast('HUGE JACKPOT! 💸', `Incredible! You won $${res.reward.toFixed(2)}!`, 'jackpot');
          } else {
             showToast('Box Opened!', `Congratulations! You won $${res.reward.toFixed(2)} from the box!`, 'success');
          }
      }
    }

    async function requestWithdrawal() {
      const address = document.getElementById('wallet-address').value;
      const amount = parseFloat(document.getElementById('withdraw-amount').value);

      if (!address || address.length < 10) {
        showToast('Invalid Address', 'Please enter a valid TON wallet address.', 'error');
        return;
      }

      if (isNaN(amount) || amount < 10) {
        showToast('Invalid Amount', 'The minimum withdrawal amount is $10.', 'error');
        return;
      }

      if (amount > userState.usdBalance) {
        showToast('Insufficient balance', 'You do not have enough funds to withdraw this amount.', 'error');
        return;
      }

      const res = await apiCall('withdraw', {amount, address});
      if(res && res.success) {
          updateLocalState(res.data);
          document.getElementById('wallet-address').value = '';
          document.getElementById('withdraw-amount').value = '';
          showToast('Withdrawal Requested', `Your request for $${amount.toFixed(2)} has been submitted.`, 'success');
      } else {
          showToast('Error', 'Invalid withdrawal request.', 'error');
      }
    }

    function renderWithdrawHistory() {
      const container = document.getElementById('withdraw-history-container');
      
      if (!userState || !userState.withdrawHistory || userState.withdrawHistory.length === 0) {
        container.innerHTML = `
          <div class="glass-card rounded-2xl p-6 text-center border-dashed border-2 border-slate-700">
            <i class="fa-solid fa-clock-rotate-left text-3xl text-slate-600 mb-2"></i>
            <p class="text-xs font-bold text-slate-500 uppercase tracking-widest">History is Empty</p>
          </div>`;
        return;
      }

      container.innerHTML = '';
      userState.withdrawHistory.forEach(record => {
        container.innerHTML += `
          <div class="glass-card rounded-2xl p-4 flex justify-between items-center transition-transform hover:-translate-y-0.5">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-full bg-slate-800 border border-slate-700 flex items-center justify-center">
                 <i class="fa-solid fa-arrow-right-arrow-left text-slate-400"></i>
              </div>
              <div>
                <p class="text-xs font-black text-white">${record.id} <span class="text-[9px] text-slate-400 ml-1 font-bold">${record.date}</span></p>
                <p class="text-[10px] text-blue-400 mt-0.5 font-mono bg-blue-500/10 inline-block px-1.5 py-0.5 rounded">${record.address}</p>
              </div>
            </div>
            <div class="text-right">
              <p class="text-sm font-black text-emerald-400 drop-shadow-[0_0_5px_rgba(16,185,129,0.5)]">-$${record.amount.toFixed(2)}</p>
              <p class="text-[9px] font-black text-amber-400 uppercase tracking-widest mt-0.5 bg-amber-500/10 inline-block px-2 py-0.5 rounded-full border border-amber-500/20">${record.status}</p>
            </div>
          </div>
        `;
      });
    }

    // REFERRALS LOGIC
    function copyRefLink() {
        const link = `https://t.me/pointplayappbot?startapp=${userState.tgId}`;
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(link).then(() => {
                showToast('Success', 'Referral link copied!', 'success');
            });
        } else {
            let textArea = document.createElement("textarea");
            textArea.value = link;
            textArea.style.position = "fixed";
            textArea.style.left = "-999999px";
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            try {
                document.execCommand('copy');
                showToast('Success', 'Referral link copied!', 'success');
            } catch (err) {}
            textArea.remove();
        }
    }

    function shareRefLink() {
        const link = `https://t.me/pointplayappbot?startapp=${userState.tgId}`;
        const text = "🎯 Play games, complete tasks, earn XP, and collect exciting rewards 🚀 I’m already playing on Point Play now it’s your turn to join the adventure👇\n" + link;
        const shareUrl = `https://t.me/share/url?url=${encodeURIComponent(link)}&text=${encodeURIComponent(text)}`;
        tg.openTelegramLink(shareUrl);
    }

    async function loadReferralsPage() {
        const res = await apiCall('get_referrals');
        if(res && res.success) {
            document.getElementById('ref-total').innerText = res.data.total;
            document.getElementById('ref-pending').innerText = res.data.pending;
            document.getElementById('ref-approved').innerText = res.data.approved;
            
            const listContainer = document.getElementById('referrals-list-container');
            listContainer.innerHTML = '';
            if(res.data.list.length === 0) {
               listContainer.innerHTML = '<p class="text-center text-slate-500 text-xs">No referrals yet.</p>';
            } else {
               res.data.list.forEach(r => {
                   let adsC = r.ads >= 25 ? 'text-emerald-400' : 'text-white';
                   let tasksC = r.tasks >= 5 ? 'text-emerald-400' : 'text-white';
                   let statusC = r.status === 'Approved' ? 'text-emerald-400 bg-emerald-500/10 border-emerald-500/20' : 'text-amber-400 bg-amber-500/10 border-amber-500/20';
                   listContainer.innerHTML += `
                     <div class="glass-card rounded-2xl p-3 border border-slate-800">
                        <div class="flex justify-between items-center mb-2">
                           <span class="font-bold text-white text-sm truncate pr-2"><i class="fa-solid fa-user-astronaut text-slate-500 mr-2"></i>${r.name} ${r.username ? '(@'+r.username+')' : ''}</span>
                           <span class="text-[9px] font-black uppercase px-2 py-1 rounded-md border ${statusC}">${r.status}</span>
                        </div>
                        <div class="flex gap-4 text-[10px] text-slate-400 font-bold bg-[#050511]/50 p-2 rounded-xl">
                           <span class="flex-1 text-center"><i class="fa-solid fa-video mr-1"></i> Ads: <span class="${adsC}">${r.ads} / 25</span></span>
                           <div class="w-[1px] bg-slate-700"></div>
                           <span class="flex-1 text-center"><i class="fa-solid fa-list-check mr-1"></i> Tasks: <span class="${tasksC}">${r.tasks} / 5</span></span>
                        </div>
                     </div>
                   `;
               });
            }

            const histContainer = document.getElementById('referral-history-container');
            histContainer.innerHTML = '';
            if(res.data.history.length === 0) {
               histContainer.innerHTML = '<p class="text-center text-slate-500 text-xs">No rewards yet.</p>';
            } else {
               res.data.history.forEach(h => {
                   histContainer.innerHTML += `
                     <div class="glass-card rounded-2xl p-3 flex justify-between items-center border border-slate-800">
                        <div class="flex items-center gap-3">
                           <div class="w-8 h-8 rounded-full bg-blue-500/20 border border-blue-500/30 flex items-center justify-center">
                              <i class="fa-solid fa-gift text-crypto-glow text-xs"></i>
                           </div>
                           <div>
                              <p class="font-bold text-white text-xs">${h.text} <span class="text-slate-400 text-[10px] ml-1">(${h.user})</span></p>
                              <p class="text-[9px] text-slate-500 mt-0.5">${h.date}</p>
                           </div>
                        </div>
                        <div class="text-right">
                           <p class="font-black text-crypto-glow text-xs drop-shadow-[0_0_5px_#00f0ff]">+${h.xp} XP</p>
                           <p class="font-black text-emerald-400 text-[10px] mt-0.5">+$${h.usd}</p>
                        </div>
                     </div>
                   `;
               });
            }
        }
    }

    function switchTab(tabId) {
      if(window.gameEngine && window.gameEngine.isPlaying && tabId !== 'games') {
         exitGame(); 
      }

      document.querySelectorAll('.view-section').forEach(el => el.classList.add('hidden'));
      document.querySelectorAll('.nav-btn').forEach(btn => {
        btn.classList.remove('nav-active');
      });

      document.getElementById(`view-${tabId}`).classList.remove('hidden');
      
      const activeBtn = document.querySelector(`[data-target="${tabId}"]`);
      if(activeBtn) activeBtn.classList.add('nav-active');
      
      if(tabId === 'referrals') {
          loadReferralsPage();
      }

      const header = document.getElementById('main-header');
      const mainContent = document.getElementById('app-content');

      clearTimeout(headerTimeout);

      if (tabId === 'withdraw' || tabId === 'profile' || tabId === 'games' || tabId === 'referrals') {
        header.style.transform = 'translateY(-100%)';
        headerTimeout = setTimeout(() => header.style.display = 'none', 300);
        mainContent.classList.remove('pt-24');
        mainContent.classList.add('pt-4');
      } else {
        header.style.display = 'block'; 
        setTimeout(() => header.style.transform = 'translateY(0)', 10);
        mainContent.classList.remove('pt-4');
        mainContent.classList.add('pt-24');
      }
      
      if (tg.HapticFeedback) tg.HapticFeedback.selectionChanged();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function updateTimer() {
        const now = getNow();
        const timerEl = document.getElementById('reset-timer');
        
        if (!now) {
            if (timerEl) timerEl.innerText = "--:--:--";
            return;
        }

        const tomorrow = new Date(now);
        tomorrow.setUTCHours(24, 0, 0, 0); 
        
        const diff = tomorrow.getTime() - now.getTime();
        const h = Math.floor(diff / 1000 / 60 / 60);
        const m = Math.floor((diff / 1000 / 60) % 60);
        const s = Math.floor((diff / 1000) % 60);
        
        if (timerEl) {
            timerEl.innerText = `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
        }
    }
    
    async function initApp() {
      await fetchServerTime(); 
      const res = await apiCall('sync');
      
      setTimeout(() => {
        document.getElementById('loading-overlay').style.opacity = '0';
        setTimeout(() => {
          document.getElementById('loading-overlay').style.display = 'none';
        }, 500); 
      }, 500);

      if(res && res.success) {
          updateLocalState(res.data);
      }
      
      setInterval(updateTimer, 1000);
      updateTimer();
    }

    // ==========================================
    // GAME ENGINE: Point Play Balloon Blast
    // ==========================================

    const LEVEL_REWARDS = [25, 35, 50, 60, 75, 80, 90, 100, 110, 125]; 

    function getDailyRandom(seed) {
      let t = seed += 0x6D2B79F5;
      return function() {
        t = Math.imul(t ^ t >>> 15, t | 1);
        t ^= t + Math.imul(t ^ t >>> 7, t | 61);
        return ((t ^ t >>> 14) >>> 0) / 4294967296;
      }
    }

    function renderGameMap() {
      if(!gameState) return;
      const container = document.getElementById('level-map');
      container.innerHTML = '';
      
      let html = '';
      const positions = [
        {left: '50%'}, {left: '70%'}, {left: '80%'}, {left: '60%'}, {left: '30%'},
        {left: '20%'}, {left: '40%'}, {left: '60%'}, {left: '80%'}, {left: '50%'}
      ];

      for (let i = 0; i < 10; i++) {
        let isCompleted = gameState.levelsCompleted[i];
        let isCurrent = !isCompleted && (i === 0 || gameState.levelsCompleted[i-1]);
        let isLocked = !isCompleted && !isCurrent;
        
        let stateClass = isCompleted ? 'completed' : (isCurrent ? 'current cursor-pointer' : 'locked');
        let icon = isLocked ? '<i class="fa-solid fa-lock text-lg"></i>' : (i + 1);
        let onClick = isCurrent || isCompleted ? `onclick="startGame(${i + 1})"` : `onclick="showToast('Locked', 'Complete previous levels to unlock!', 'error')"`;
        
        let starsHtml = '';
        if (isCompleted) {
            let sCount = gameState.stars[i];
            starsHtml = `
              <div class="absolute -bottom-4 w-full flex justify-center gap-0.5">
                <i class="fa-solid fa-star star-icon ${sCount >= 1 ? 'filled' : ''}"></i>
                <i class="fa-solid fa-star star-icon ${sCount >= 2 ? 'filled' : ''} -translate-y-1"></i>
                <i class="fa-solid fa-star star-icon ${sCount >= 3 ? 'filled' : ''}"></i>
              </div>`;
        }

        let lineHtml = '';
        if (i < 9) {
           let isLineActive = isCompleted;
           lineHtml = `<div class="absolute left-1/2 -translate-x-1/2 top-full h-8 w-1.5 ${isLineActive ? 'bg-crypto-glow shadow-[0_0_10px_#00f0ff]' : 'bg-slate-700'} -z-10 rounded-full"></div>`;
        }

        html += `
          <div class="relative w-full flex justify-center mb-10 group" style="transform: translateX(calc(${positions[i].left} - 50%))">
             <div class="level-node ${stateClass} shadow-lg" ${onClick}>
               ${icon}
               ${starsHtml}
             </div>
             ${lineHtml}
          </div>
        `;
      }
      container.innerHTML = html;
    }

    let gameEngine = {
      canvas: null, ctx: null, isPlaying: false,
      balloons: [], projectiles: [], particles: [], fallingBalloons: [], floatingTexts: [],
      gridCols: 8, ballRadius: 0,
      grid: [], 
      colors: ['#ef4444', '#3b82f6', '#10b981', '#eab308', '#a855f7', '#06b6d4'],
      launcherColor: '', nextColor: '', activePowerup: null,
      colorHistory: [], 
      currentLevel: 1, score: 0, shotsFired: 0, totalTarget: 0,
      offsetY: 0, dropInterval: 5, limitY: 0,
      mouseX: null, mouseY: null, rand: null,
      containers: null
    };

    function startGame(level) {
      if (!isTimeSynced) {
         showToast('Error', 'Server time could not be verified. Please check your connection.', 'error');
         return;
      }
        
      document.body.classList.add('in-game');
      document.getElementById('game-play-container').classList.add('fullscreen-mode');
      
      document.getElementById('game-menu-container').classList.add('hidden');
      document.getElementById('game-play-container').classList.remove('hidden');
      document.getElementById('game-over-modal').classList.add('hidden');
      
      const splash = document.getElementById('splash-screen');
      splash.style.display = 'flex';
      splash.style.opacity = '1';
      
      setTimeout(() => {
        splash.style.opacity = '0';
        setTimeout(() => { splash.style.display = 'none'; }, 500);
      }, 1500);

      initGameEngine(level);
    }

    function exitGame() {
      gameEngine.isPlaying = false;
      document.body.classList.remove('in-game');
      document.getElementById('game-play-container').classList.remove('fullscreen-mode');
      
      document.getElementById('game-play-container').classList.add('hidden');
      document.getElementById('game-menu-container').classList.remove('hidden');
      updatePowerupUI();
      renderGameMap();
    }

    function initGameEngine(level) {
      gameEngine.currentLevel = level;
      gameEngine.canvas = document.getElementById('balloon-canvas');
      gameEngine.ctx = gameEngine.canvas.getContext('2d');
      gameEngine.isPlaying = true;
      gameEngine.score = 0;
      gameEngine.shotsFired = 0;
      gameEngine.offsetY = 0;
      gameEngine.balloons = [];
      gameEngine.projectiles = [];
      gameEngine.particles = [];
      gameEngine.fallingBalloons = [];
      gameEngine.floatingTexts = [];
      gameEngine.activePowerup = null;
      gameEngine.colorHistory = [];

      document.getElementById('ingame-level-display').innerText = level;
      document.getElementById('ingame-score').innerText = '0';
      updateStarsUI(0);
      updatePowerupUI();

      const seedStr = gameState.seedDate.replace(/-/g, '') + level.toString();
      gameEngine.rand = getDailyRandom(parseInt(seedStr));

      resizeCanvas();
      generateLevel(level);
      
      let avail = getAvailableColors();
      gameEngine.launcherColor = avail[Math.floor(gameEngine.rand() * avail.length)];
      updateColorHistory(gameEngine.launcherColor);
      gameEngine.nextColor = gameEngine.launcherColor; 
      updateLauncherColors(true); 

      gameEngine.canvas.addEventListener('touchstart', handleInput, {passive: false});
      gameEngine.canvas.addEventListener('touchmove', handleMove, {passive: false});
      gameEngine.canvas.addEventListener('touchend', handleFire, {passive: false});
      gameEngine.canvas.addEventListener('mousedown', handleInput);
      gameEngine.canvas.addEventListener('mousemove', handleMove);
      gameEngine.canvas.addEventListener('mouseup', handleFire);
      
      window.requestAnimationFrame(gameLoop);
    }

    // Engine Support Stubs for compatibility
    function resizeCanvas() {
        if(gameEngine.canvas) {
            gameEngine.canvas.width = gameEngine.canvas.parentElement.clientWidth;
            gameEngine.canvas.height = gameEngine.canvas.parentElement.clientHeight;
        }
    }
    function generateLevel(level) {}
    function getAvailableColors() { return gameEngine.colors; }
    function updateColorHistory(c) {}
    function updateLauncherColors(initial) {}
    function handleInput(e) { if(e.preventDefault) e.preventDefault(); }
    function handleMove(e) { if(e.preventDefault) e.preventDefault(); }
    function handleFire(e) { if(e.preventDefault) e.preventDefault(); }
    function updateStarsUI(s) {}
    function updatePowerupUI() {}
    function activatePowerup(type) {}
    function nextLevel() {}
    function gameLoop() {
        if(!gameEngine.isPlaying) return;
        gameEngine.ctx.clearRect(0,0,gameEngine.canvas.width, gameEngine.canvas.height);
        window.requestAnimationFrame(gameLoop);
    }

    // Initialize application natively on load
    window.onload = () => {
        initApp();
    };

  </script>
</body>
</html>
