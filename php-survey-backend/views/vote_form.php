<?php
/** @var array $config */
// Inherited from parent scope: $verified, $is_admin, $is_read_only, $show_voted_message
if (!isset($config) || !is_array($config)) {
  echo '<div class="alert alert-danger">Configuration unavailable.</div>';
  return;
}

// Check for the presence of the X25519 public key for the project
$pubkey_file = __DIR__ . "/../data/pubkey.txt";
$pubkey_exists = file_exists($pubkey_file) && strlen(base64_decode(trim(file_get_contents($pubkey_file)), true)) === 32;
if (!$pubkey_exists) {
  echo '<div class="alert alert-warning">Voting is unavailable: the public encryption key has not been initialized yet.<br>Contact the administrator.</div>';
  return;
}

// Check voting status
$voting_status = get_voting_status($config['voting_state_json'], $config['votes_dir']);
$voting_is_open = $voting_status['is_open'];

// Show voting status
if (!$voting_is_open) {
  echo '<div class="alert alert-warning">';
  echo '<strong>Voting is currently closed.</strong><br>';

  if ($voting_status['period_configured'] && $voting_status['period_future']) {
    $next_start = new DateTimeImmutable($voting_status['period']['start'], new DateTimeZone('UTC'));
    echo 'Voting will open on: <strong>' . $next_start->format('Y-m-d H:i') . ' UTC</strong>';
  } elseif (!$voting_status['period_configured']) {
    echo 'No voting period is currently scheduled.';
  } else {
    // Generic message for closed voting (do not reveal admin manual actions)
    echo 'Please check back later.';
  }

  echo '</div>';

  // Force read-only if voting is closed
  $is_read_only = true;
} elseif ($voting_status['period_active']) {
  $active_end = new DateTimeImmutable($voting_status['period']['end'], new DateTimeZone('UTC'));
  echo '<div class="alert alert-info">';
  echo '<strong>Voting open until: ' . $active_end->format('Y-m-d H:i') . ' UTC</strong>';
  echo '</div>';
}

// Check if should show "already voted" message
if (!empty($show_voted_message)) {
  $style = !empty($flash_was_shown) ? ' style="display: none;"' : '';
  echo '<div class="alert alert-info already-voted"' . $style . '>Your vote has already been recorded. Thank you for your participation!</div>';
  echo '<br>';
}

// Determine disabled state for form inputs
$disabled_attr = !empty($is_read_only) ? ' disabled' : '';
$checkbox_class = !empty($is_read_only) ? ' vote-readonly-checkbox' : '';
$button_class = !empty($is_read_only) ? ' vote-readonly-button' : '';

// Determine voting method
$is_stv = ($config['voting_method'] ?? 'approval') === 'stv';
$stv_require_full = (bool)($config['stv_require_full_ranking'] ?? false);
$stv_min_rankings = max(1, (int)($config['stv_min_rankings'] ?? 1));

// Load candidates here for both modes
$candidates = load_candidates($config['candidates_json']);
if (!empty($candidates)) {
  shuffle($candidates); // Randomize candidate cards on each render
}
$num_candidates = count($candidates);
?>
<section class="flow-step">
  <p class="step-eyebrow">Step 4 · Choose your board</p>
  <?php if ($is_stv): ?>
  <h2 class="step-title">
    Rank candidates in order of preference
  </h2>
  <p class="flow-copy">
    Drag candidates from the pool to your ranking list. Your first choice is your most preferred candidate.
    <?php if ($stv_require_full): ?>
    You must rank all <?php echo $num_candidates; ?> candidates.
    <?php else: ?>
    Rank at least <?php echo $stv_min_rankings; ?> candidate<?php echo $stv_min_rankings > 1 ? 's' : ''; ?>.
    <?php endif; ?>
  </p>

  <div class="count-pill" id="selection-counter">
    <strong><span id="counter-value">0</span> candidate(s) ranked</strong>
    <span id="counter-status"><?php
      if ($stv_require_full) {
        echo "Rank all $num_candidates candidates";
      } else {
        echo "Rank at least $stv_min_rankings";
      }
    ?></span>
  </div>
  <?php else: ?>
  <h2 class="step-title">
    Select between <?php echo (int) $config['min_picks']; ?> and <?php echo (int) $config['max_picks']; ?> candidates
  </h2>
  <p class="flow-copy">
    Browse the full list in one clean view. Tap a card to select it—each click feels like a calm conversation.
  </p>

  <div class="count-pill" id="selection-counter">
    <strong><span id="counter-value">0</span> candidate(s) selected</strong>
    <span id="counter-status">Pick at least <?php echo (int) $config['min_picks']; ?></span>
  </div>
  <?php endif; ?>

  <form method="post" class="flow-form" id="vote-form">
    <input type="hidden" name="action" value="cast">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <?php if ($is_stv): ?>
    <!-- STV Mode: Drag-and-drop ranking -->
    <input type="hidden" name="voting_method" value="stv">
    <input type="hidden" name="rankings" id="rankings-input" value="">

    <div class="stv-container">
      <div class="stv-section stv-ranked">
        <h3 class="stv-section-title">Your Ranking</h3>
        <p class="stv-section-hint">Drag candidates here to rank them. #1 is your top choice.</p>
        <div class="stv-list" id="ranked-list"></div>
      </div>

      <div class="stv-section stv-unranked">
        <h3 class="stv-section-title">Unranked Candidates</h3>
        <p class="stv-section-hint">Drag from here to rank, or click to add.</p>
        <div class="stv-list" id="unranked-list">
          <?php foreach ($candidates as $candidate):
            $statement = trim($candidate['statement'] ?? '');
            $statement_id = 'statement-' . preg_replace('/[^a-z0-9_\-]/i', '', (string) $candidate['id']);
            $image_url = candidate_image_url($candidate, $config);
            $manifestos_enabled_globally = $config['show_manifestos'] ?? true;
            $manifesto_enabled_for_candidate = $candidate['manifesto_enabled'] ?? true;
            $should_show_manifesto = $manifestos_enabled_globally && $manifesto_enabled_for_candidate && !empty($statement);
            ?>
            <div class="stv-candidate" data-id="<?= h($candidate['id']) ?>">
              <span class="stv-rank-badge"></span>
              <img src="<?= h($image_url) ?>" alt="<?= h($candidate['name']) ?>" class="stv-candidate-image">
              <div class="stv-candidate-info">
                <span class="stv-candidate-name"><?= h($candidate['name']) ?></span>
                <div class="stv-candidate-actions">
                  <?php if (!empty($candidate['linkedin'])): ?>
                    <a href="<?= h($candidate['linkedin']) ?>" target="_blank" rel="noopener" class="stv-linkedin" onclick="event.stopPropagation()">
                      <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>
                      </svg>
                    </a>
                  <?php endif; ?>
                  <?php if ($should_show_manifesto): ?>
                    <button type="button" class="stv-manifesto-btn" data-target="<?= h($statement_id) ?>" onclick="event.stopPropagation()">Manifest</button>
                  <?php endif; ?>
                  <button type="button" class="stv-remove-btn" title="Remove from ranking" style="display:none;" onclick="event.stopPropagation()">✕</button>
                </div>
              </div>
              <?php if ($should_show_manifesto): ?>
              <div class="stv-statement" id="<?= h($statement_id) ?>" hidden>
                <div class="stv-statement-content"><?= $statement ?></div>
              </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php else: ?>
    <!-- Approval Mode: Checkboxes -->
    <input type="hidden" name="voting_method" value="approval">
    <div class="candidate-list">
      <?php foreach ($candidates as $candidate):
        $statement = trim($candidate['statement'] ?? '');
        $statement_id = 'statement-' . preg_replace('/[^a-z0-9_\-]/i', '', (string) $candidate['id']);

        // Resolve candidate image from available files (png, jpg, webp, etc.) with placeholder fallback
        $image_url = candidate_image_url($candidate, $config);
        ?>
        <div class="candidate-card-wrapper">
          <div class="candidate-card<?= $checkbox_class ?>">
            <label class="candidate-card-main">
              <input type="checkbox" name="choices[]" value="<?= h($candidate['id']) ?>" <?= $disabled_attr ?>>
              <img src="<?= h($image_url) ?>" alt="<?= h($candidate['name']) ?>" class="candidate-image">
              <div class="candidate-info">
                <span class="candidate-name"><?= h($candidate['name']) ?></span>
                <div class="candidate-links">
                  <?php if (!empty($candidate['linkedin'])): ?>
                    <a href="<?= h($candidate['linkedin']) ?>" target="_blank" rel="noopener" class="candidate-linkedin"
                      onclick="event.stopPropagation()">
                      <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
                        <path
                          d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z" />
                      </svg>
                      LinkedIn
                    </a>
                  <?php endif; ?>
                  <?php
                  // Check global setting AND per-candidate setting AND statement exists
                  $manifestos_enabled_globally = $config['show_manifestos'] ?? true;
                  $manifesto_enabled_for_candidate = $candidate['manifesto_enabled'] ?? true;
                  $should_show_manifesto = $manifestos_enabled_globally
                    && $manifesto_enabled_for_candidate
                    && !empty($statement);

                  if ($should_show_manifesto):
                    ?>
                    <button type="button" class="statement-toggle" data-target="<?= h($statement_id) ?>"
                      aria-expanded="false" aria-controls="<?= h($statement_id) ?>" onclick="event.stopPropagation()">
                      <span class="statement-toggle-text">Read manifest</span>
                      <svg class="statement-toggle-icon" width="14" height="14" fill="none" stroke="currentColor"
                        stroke-width="2.5" viewBox="0 0 24 24">
                        <polyline points="6 9 12 15 18 9"></polyline>
                      </svg>
                    </button>
                  <?php endif; ?>
                </div>
              </div>
            </label>
            <?php if ($should_show_manifesto): ?>
              <div class="candidate-statement" id="<?= h($statement_id) ?>" hidden>
                <div class="candidate-statement-content">
                  <?php
                  // Render HTML content from Quill editor
                  // Note: Content is stored as HTML from Quill, display it directly
                  echo $statement;
                  ?>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <p class="flow-support">
      You can change your mind until you press submit. Your selections remain private and encrypted immediately.
    </p>

    <button type="submit" id="submit-vote-btn" class="primary-btn<?= $button_class ?>" <?= $disabled_attr ?>>Submit my
      vote</button>

    <?php if (!empty($verified)): ?>
      <p class="flow-support">Verified as <strong><?= h($verified) ?></strong></p>
    <?php endif; ?>
  </form>
</section>
<?php if ($is_stv): ?>
<!-- SortableJS for drag-and-drop -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script nonce="<?= h(csp_nonce()) ?>">
(function() {
  const isReadOnly = <?= !empty($is_read_only) ? 'true' : 'false' ?>;
  const requireFull = <?= $stv_require_full ? 'true' : 'false' ?>;
  const minRankings = <?= $stv_min_rankings ?>;
  const totalCandidates = <?= $num_candidates ?>;

  const rankedList = document.getElementById('ranked-list');
  const unrankedList = document.getElementById('unranked-list');
  const rankingsInput = document.getElementById('rankings-input');
  const counterValue = document.getElementById('counter-value');
  const counterStatus = document.getElementById('counter-status');
  const submitBtn = document.getElementById('submit-vote-btn');
  const voteForm = document.getElementById('vote-form');

  function updateRankBadges() {
    const items = rankedList.querySelectorAll('.stv-candidate');
    items.forEach((item, index) => {
      const badge = item.querySelector('.stv-rank-badge');
      if (badge) badge.textContent = '#' + (index + 1);
      const removeBtn = item.querySelector('.stv-remove-btn');
      if (removeBtn) removeBtn.style.display = '';
    });
    // Hide badges in unranked list
    unrankedList.querySelectorAll('.stv-candidate').forEach(item => {
      const badge = item.querySelector('.stv-rank-badge');
      if (badge) badge.textContent = '';
      const removeBtn = item.querySelector('.stv-remove-btn');
      if (removeBtn) removeBtn.style.display = 'none';
    });
  }

  function updateRankingsInput() {
    const items = rankedList.querySelectorAll('.stv-candidate');
    const ids = Array.from(items).map(item => item.dataset.id);
    rankingsInput.value = JSON.stringify(ids);
  }

  function updateCounter() {
    const count = rankedList.querySelectorAll('.stv-candidate').length;
    counterValue.textContent = count;

    const minRequired = requireFull ? totalCandidates : minRankings;
    const isValid = count >= minRequired;

    if (isValid) {
      if (requireFull && count === totalCandidates) {
        counterStatus.textContent = '✓ All candidates ranked';
      } else {
        counterStatus.textContent = '✓ Valid ranking';
      }
      counterStatus.style.color = 'var(--success, #28a745)';
      if (!isReadOnly && submitBtn) {
        submitBtn.disabled = false;
        submitBtn.style.opacity = '';
        submitBtn.style.cursor = '';
      }
    } else {
      if (requireFull) {
        counterStatus.textContent = `Rank all ${totalCandidates} candidates`;
      } else {
        counterStatus.textContent = `(Minimum ${minRequired} required)`;
      }
      counterStatus.style.color = 'var(--danger, #dc3545)';
      if (!isReadOnly && submitBtn) {
        submitBtn.disabled = true;
        submitBtn.style.opacity = '0.5';
        submitBtn.style.cursor = 'not-allowed';
      }
    }
  }

  function onUpdate() {
    updateRankBadges();
    updateRankingsInput();
    updateCounter();
  }

  if (!isReadOnly) {
    // Initialize SortableJS for both lists
    new Sortable(rankedList, {
      group: 'candidates',
      animation: 150,
      ghostClass: 'stv-ghost',
      chosenClass: 'stv-chosen',
      dragClass: 'stv-drag',
      onSort: onUpdate
    });

    new Sortable(unrankedList, {
      group: 'candidates',
      animation: 150,
      ghostClass: 'stv-ghost',
      chosenClass: 'stv-chosen',
      dragClass: 'stv-drag',
      onSort: onUpdate
    });

    // Click to add/remove
    document.querySelectorAll('.stv-candidate').forEach(candidate => {
      candidate.addEventListener('click', function(e) {
        if (e.target.closest('.stv-linkedin') || e.target.closest('.stv-manifesto-btn') || e.target.closest('.stv-remove-btn')) return;
        const currentList = candidate.parentElement;
        if (currentList === unrankedList) {
          rankedList.appendChild(candidate);
        }
        onUpdate();
      });

      const removeBtn = candidate.querySelector('.stv-remove-btn');
      if (removeBtn) {
        removeBtn.addEventListener('click', function(e) {
          e.stopPropagation();
          unrankedList.appendChild(candidate);
          onUpdate();
        });
      }
    });

    // Form submission
    if (voteForm) {
      voteForm.addEventListener('submit', function(e) {
        updateRankingsInput();
        const count = rankedList.querySelectorAll('.stv-candidate').length;
        const minRequired = requireFull ? totalCandidates : minRankings;
        if (count < minRequired) {
          e.preventDefault();
          alert(requireFull ? `Please rank all ${totalCandidates} candidates.` : `Please rank at least ${minRequired} candidate(s).`);
          return;
        }
        submitBtn.disabled = true;
        submitBtn.textContent = 'Submitting...';
        submitBtn.style.opacity = '0.5';
        submitBtn.style.cursor = 'not-allowed';
      });
    }
  }

  // Manifesto toggle
  document.querySelectorAll('.stv-manifesto-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      const targetId = btn.dataset.target;
      const statement = document.getElementById(targetId);
      if (!statement) return;
      statement.hidden = !statement.hidden;
      btn.textContent = statement.hidden ? 'Manifest' : 'Hide';
    });
  });

  // Initialize
  updateCounter();
})();
</script>
<?php else: ?>
<script nonce="<?= h(csp_nonce()) ?>">
  const min = <?php echo (int) $config['min_picks']; ?>;
  const max = <?php echo (int) $config['max_picks']; ?>;
  const boxes = Array.from(document.querySelectorAll('input[type=checkbox][name="choices[]"]'));
  const isReadOnly = <?= !empty($is_read_only) ? 'true' : 'false' ?>;
  const counterValue = document.getElementById('counter-value');
  const counterStatus = document.getElementById('counter-status');
  const submitBtn = document.getElementById('submit-vote-btn');

  function updateCounter() {
    const checked = boxes.filter(b => b.checked);
    const count = checked.length;

    // Update counter value
    counterValue.textContent = count;

    // Update status message and submit button state
    if (count < min) {
      counterStatus.textContent = `(Minimum ${min} required)`;
      counterStatus.style.color = 'var(--danger, #dc3545)';

      // Disable submit button if minimum not reached
      if (!isReadOnly && submitBtn) {
        submitBtn.disabled = true;
        submitBtn.style.opacity = '0.5';
        submitBtn.style.cursor = 'not-allowed';
      }
    } else if (count >= min && count < max) {
      counterStatus.textContent = '✓ Valid number';
      counterStatus.style.color = 'var(--success, #28a745)';

      // Enable submit button
      if (!isReadOnly && submitBtn) {
        submitBtn.disabled = false;
        submitBtn.style.opacity = '';
        submitBtn.style.cursor = '';
      }
    } else if (count === max) {
      counterStatus.textContent = '✓ Maximum reached';
      counterStatus.style.color = 'var(--primary)';

      // Enable submit button
      if (!isReadOnly && submitBtn) {
        submitBtn.disabled = false;
        submitBtn.style.opacity = '';
        submitBtn.style.cursor = '';
      }
    } else {
      counterStatus.textContent = '';
    }
  }

  function enforce() {
    updateCounter();
    if (isReadOnly) return; // Don't enforce if read-only
    const checked = boxes.filter(b => b.checked);
    if (checked.length >= max) boxes.forEach(b => { if (!b.checked) b.disabled = true; });
    else boxes.forEach(b => b.disabled = false);
  }

  // Initialize counter on page load
  updateCounter();

  /* Statement toggle interactions */
  const statementToggles = document.querySelectorAll('.statement-toggle');

  statementToggles.forEach(toggle => {
    toggle.addEventListener('click', event => {
      event.preventDefault();
      event.stopPropagation();

      const targetId = toggle.dataset.target;
      if (!targetId) return;

      const statement = document.getElementById(targetId);
      if (!statement) return;

      const isExpanded = toggle.getAttribute('aria-expanded') === 'true';

      if (isExpanded) {
        // Collapse
        statement.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
        toggle.querySelector('.statement-toggle-text').textContent = 'Read manifest';
      } else {
        // Expand
        statement.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
        toggle.querySelector('.statement-toggle-text').textContent = 'Hide manifest';
      }
    });
  });

  if (!isReadOnly) {
    boxes.forEach(b => b.addEventListener('change', enforce));
  }

  const voteForm = document.querySelector('.flow-form');
  if (voteForm && submitBtn && !isReadOnly) {
    voteForm.addEventListener('submit', function () {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Submitting...';
      submitBtn.style.opacity = '0.5';
      submitBtn.style.cursor = 'not-allowed';
    });
  }

  // Hide flash message after 5 seconds and show already voted message
  const flashElement = document.querySelector('.flash');
  if (flashElement) {
    setTimeout(() => {
      flashElement.style.display = 'none';
      const alreadyVoted = document.querySelector('.already-voted');
      if (alreadyVoted) alreadyVoted.style.display = 'block';
    }, 5000);
  }
</script>
<?php endif; ?>
<script nonce="<?= h(csp_nonce()) ?>">
  // Heartbeat to keep session alive while user is reading/voting
  // Pings every 60 seconds
  setInterval(() => {
    fetch('?action=ping')
      .then(r => r.json())
      .catch(console.error);
  }, 60000);
</script>
<?php
?>