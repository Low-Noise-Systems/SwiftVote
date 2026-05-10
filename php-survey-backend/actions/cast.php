<?php
/** @var array $config */
if (!isset($config) || !is_array($config)) {
    http_response_code(500);
    exit('Configuration not loaded.');
}
$action = $action ?? ($_GET['action'] ?? $_POST['action'] ?? null);

if ($action === 'cast' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf($_POST['csrf'] ?? '');

    // SECURITY: Check if voting is open
    if (!is_voting_open($config['voting_state_json'])) {
        $_SESSION['flash'] = ['err', 'The vote is not currently open. Please try again during the voting period.'];
        header('Location: ' . $_SERVER['REQUEST_URI']); exit;
    }

    // SECURITY: Block voting when logged in as admin
    // Admins can vote, but only when logged in through the voter page
    if (!empty($_SESSION['admin_email'])) {
        $_SESSION['flash'] = ['err', 'You cannot vote from the admin session. Please log out and use the normal voting page.'];
        header('Location: ' . $_SERVER['REQUEST_URI']); exit;
    }

    // Use voter_email (separate from admin authentication)
    $email = $_SESSION['voter_email'] ?? null;
    if (!$email) { http_response_code(403); exit('Not verified'); }

    $sealInfo = sealed_box_support_info();
    if (!$sealInfo['ok']) {
        $reason = $sealInfo['error'] ?? 'support indisponible';
        $_SESSION['flash'] = ['err', 'Ballot encryption is unavailable (' . $reason . '). Enable the PHP sodium extension on the hosting.'];
        header('Location: ' . $_SERVER['REQUEST_URI']); exit;
    }

    $pubkeyFile = __DIR__ . '/../data/pubkey.txt';
    if (!file_exists($pubkeyFile)) {
        $_SESSION['flash'] = ['err', 'Voting is unavailable: the public key is not initialized (missing file).'];
        header('Location: ' . $_SERVER['REQUEST_URI']); exit;
    }

    $pubkeyContents = file_get_contents($pubkeyFile);
    if ($pubkeyContents === false) {
        $_SESSION['flash'] = ['err', 'Unable to load the public encryption key (read failed). Try again later.'];
        header('Location: ' . $_SERVER['REQUEST_URI']); exit;
    }

    $pubkeyB64 = trim($pubkeyContents);
    $pubkeyBin = base64_decode($pubkeyB64, true);
    if ($pubkeyBin === false || strlen($pubkeyBin) !== 32) {
        $_SESSION['flash'] = ['err', 'Voting is unavailable: invalid public key (expected length 32 bytes).'];
        header('Location: ' . $_SERVER['REQUEST_URI']); exit;
    }

    // Load candidates from JSON
    $candidates = load_candidates($config['candidates_json']);
    if (empty($candidates)) {
        $_SESSION['flash'] = ['err', 'Invalid or missing candidate configuration.'];
        header('Location: ' . $_SERVER['REQUEST_URI']); exit;
    }

    $valid_ids = array_column($candidates, 'id');
    $num_candidates = count($valid_ids);

    // Detect voting method
    $voting_method = $_POST['voting_method'] ?? ($config['voting_method'] ?? 'approval');
    if (!in_array($voting_method, ['approval', 'stv'], true)) {
        $voting_method = 'approval';
    }

    $timestamp = now_iso();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200);

    // Process vote based on voting method
    if ($voting_method === 'stv') {
        // STV Mode: Process rankings
        $rankings_json = $_POST['rankings'] ?? '[]';
        $rankings = json_decode($rankings_json, true);
        if (!is_array($rankings)) $rankings = [];

        // Validate rankings: must be strings and valid candidate IDs
        $valid_rankings = [];
        foreach ($rankings as $candidate_id) {
            $candidate_id = (string)$candidate_id;
            if (in_array($candidate_id, $valid_ids) && !in_array($candidate_id, $valid_rankings)) {
                $valid_rankings[] = $candidate_id;
            }
        }

        // Check STV requirements
        $stv_require_full = (bool)($config['stv_require_full_ranking'] ?? false);
        $stv_min_rankings = max(1, (int)($config['stv_min_rankings'] ?? 1));

        if ($stv_require_full) {
            if (count($valid_rankings) !== $num_candidates) {
                $_SESSION['flash'] = ['err', "Please rank all {$num_candidates} candidates."];
                header('Location: ' . $_SERVER['REQUEST_URI']); exit;
            }
        } else {
            if (count($valid_rankings) < $stv_min_rankings) {
                $_SESSION['flash'] = ['err', "Please rank at least {$stv_min_rankings} candidate(s)."];
                header('Location: ' . $_SERVER['REQUEST_URI']); exit;
            }
        }

        // Create vote data with rankings
        $vote_data = [
            'timestamp' => $timestamp,
            'voting_method' => 'stv',
            'rankings' => $valid_rankings,
            'ip' => $ip,
            'ua' => $ua
        ];

        $valid_submitted_ids = $valid_rankings; // For receipt email
    } else {
        // Approval Mode: Process choices (checkboxes)
        $choices = $_POST['choices'] ?? [];
        if (!is_array($choices)) $choices = [];
        $submitted_ids = array_values(array_unique(array_map('strval', $choices)));
        $valid_submitted_ids = array_values(array_intersect($submitted_ids, $valid_ids));

        $max = (int)$config['max_picks'];
        $min = (int)($config['min_picks'] ?? 1);

        if (count($valid_submitted_ids) < $min) {
            $_SESSION['flash'] = ['err', "Please select at least {$min} candidate(s)."];
            header('Location: ' . $_SERVER['REQUEST_URI']); exit;
        }
        if (count($valid_submitted_ids) > $max) {
            $_SESSION['flash'] = ['err', "You have selected too many candidates (max {$max})."];
            header('Location: ' . $config['base_url']); exit;
        }

        // Create binary vote format (1/0 for each candidate)
        $votes_binary = [];
        foreach ($valid_ids as $candidate_id) {
            $votes_binary[$candidate_id] = in_array($candidate_id, $valid_submitted_ids) ? 1 : 0;
        }

        // Create vote data with binary votes
        $vote_data = [
            'timestamp' => $timestamp,
            'voting_method' => 'approval',
            'votes' => $votes_binary,
            'ip' => $ip,
            'ua' => $ua
        ];
    }

    // Check if anonymous voter export is enabled
    $anonymous_mode = $config['anonymous_voter_export'] ?? false;

    if ($anonymous_mode) {
        // Generate anonymous voter ID and save mapping
        $voter_id = generate_voter_id();

        // Use separate mapping pubkey if available, otherwise fall back to main pubkey
        $mapping_pubkey_b64 = get_mapping_pubkey($config);
        if ($mapping_pubkey_b64) {
            $mapping_pubkey_bin = base64_decode($mapping_pubkey_b64, true);
            if ($mapping_pubkey_bin === false || strlen($mapping_pubkey_bin) !== 32) {
                $_SESSION['flash'] = ['err', 'Invalid mapping encryption key. Contact administrator.'];
                header('Location: ' . $_SERVER['REQUEST_URI']); exit;
            }
        } else {
            // Fallback to main pubkey if mapping key not yet configured
            $mapping_pubkey_bin = $pubkeyBin;
        }

        if (!save_voter_mapping($voter_id, $email, $mapping_pubkey_bin, $config)) {
            $_SESSION['flash'] = ['err', 'Unable to save voter mapping. Try again.'];
            header('Location: ' . $_SERVER['REQUEST_URI']); exit;
        }
        $vote_data['voter_id'] = $voter_id;
    } else {
        // Normal mode: store email in vote
        $vote_data['email'] = $email;
    }

    // Serialize to JSON and encrypt
    $vote_json = json_encode($vote_data, JSON_UNESCAPED_UNICODE);
    $sealed = sealed_box_encrypt($vote_json, $pubkeyBin);
    if (!is_string($sealed) || $sealed === '') {
        $provider = $sealInfo['provider'] ?? 'inconnu';
        $_SESSION['flash'] = ['err', 'Vote encryption failed (provider ' . $provider . '). Try again.'];
        header('Location: ' . $_SERVER['REQUEST_URI']); exit;
    }

    // Base64 encode and write to individual file (atomic operation)
    $vote_encrypted_b64 = base64_encode($sealed);
    $vote_file = get_vote_path($email, $config['votes_dir'], $config['secret_key']);

    $ok = false;

    if (!$config['allow_multiple_votes']) {
        if (file_exists($vote_file)) {
            $_SESSION['flash'] = ['err', 'A vote has already been recorded for this address.'];
            header('Location: ' . $config['base_url']);
            exit;
        }

        // Use exclusive mode to atomically check and create the file
        $fp_enc = fopen($vote_file, 'x');

        if ($fp_enc) {
            $ok = fwrite($fp_enc, $vote_encrypted_b64) !== false;
            fclose($fp_enc);

            if (!$ok) {
                // Clean up empty file on write failure
                if (file_exists($vote_file)) unlink($vote_file);
            }
        } else {
            // If creation failed and file appeared during the attempt, treat as already voted
            if (file_exists($vote_file)) {
                $_SESSION['flash'] = ['err', 'A vote has already been recorded for this address.'];
            } else {
                $_SESSION['flash'] = ['err', 'Vote recording failed. Try again.'];
            }
            header('Location: ' . $config['base_url']);
            exit;
        }
    } else {
        // If multiple votes are allowed, just write/overwrite the file
        $ok = (file_put_contents($vote_file, $vote_encrypted_b64, LOCK_EX) !== false);
    }

    if ($ok) {
        // Convert IDs back to names for receipt email
        $candidate_names = [];
        if ($voting_method === 'stv') {
            // For STV: preserve ranking order
            foreach ($valid_submitted_ids as $candidate_id) {
                foreach ($candidates as $candidate) {
                    if ($candidate['id'] === $candidate_id) {
                        $candidate_names[] = $candidate['name'];
                        break;
                    }
                }
            }
        } else {
            // For Approval: order doesn't matter
            foreach ($candidates as $candidate) {
                if (in_array($candidate['id'], $valid_submitted_ids)) {
                    $candidate_names[] = $candidate['name'];
                }
            }
        }

        // Receipt (best effort)
        send_smtp_mail(
            $config,
            $email,
            mail_subject('receipt', $config),
            render_receipt_mail($email, $candidate_names, $config, $voting_method)
        );
        $_SESSION['flash'] = ['ok', 'Vote recorded. Thank you!'];
        $_SESSION['last_activity'] = time();
    } else {
        $_SESSION['flash'] = ['err', 'Vote recording failed. Try again.'];
    }
    header('Location: ' . $_SERVER['REQUEST_URI']); exit;
}
?>
