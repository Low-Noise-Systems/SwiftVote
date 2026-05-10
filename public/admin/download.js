const form = document.getElementById('download-csv-form');
const overlay = document.getElementById('mnemonic-overlay');
const textarea = document.getElementById('mnemonic-input');
const errorEl = document.getElementById('mnemonic-error');
const statusEl = document.getElementById('mnemonic-status');
const confirmBtn = document.getElementById('mnemonic-confirm');
const cancelBtn = document.getElementById('mnemonic-cancel');
const cancelTopBtn = document.getElementById('mnemonic-cancel-top');
const openPageBtn = document.getElementById('open-mnemonic-page');
const downloadBtn = document.getElementById('download-csv-btn');
const isVotingOpen = form ? form.dataset.votingOpen === 'true' : false;

if (form && overlay && textarea && confirmBtn && cancelBtn) {
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    resetModalState();
    openModal();
  });

  confirmBtn.addEventListener('click', onConfirm);
  cancelBtn.addEventListener('click', closeModal);
  if (cancelTopBtn) cancelTopBtn.addEventListener('click', closeModal);
  if (openPageBtn) {
    openPageBtn.addEventListener('click', () => {
      window.open('vote-key/index.php', '_blank', 'noopener');
    });
  }

  overlay.addEventListener('click', (event) => {
    if (event.target === overlay) {
      closeModal();
    }
  });

  overlay.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      closeModal();
    }
  });
}

function openModal() {
  overlay.hidden = false;

  // Show/hide mapping key notice based on download type and key configuration
  const mappingKeyNotice = document.getElementById('mapping-key-notice');
  const hasMappingKey = overlay?.dataset?.hasMappingKey === 'true';
  const downloadType = overlay?.dataset?.downloadType || 'votes';

  if (mappingKeyNotice) {
    if (downloadType === 'mapping' && hasMappingKey) {
      mappingKeyNotice.style.display = 'block';
    } else {
      mappingKeyNotice.style.display = 'none';
    }
  }

  // Update modal title based on download type
  const modalTitle = document.getElementById('mnemonic-title');
  if (modalTitle) {
    modalTitle.textContent = downloadType === 'mapping'
      ? 'Decrypt voter mapping'
      : 'Decrypt CSV file';
  }

  requestAnimationFrame(() => {
    textarea.focus();
  });
}

function closeModal() {
  overlay.hidden = true;
  if (overlay) overlay.dataset.downloadType = 'votes'; // Reset to default
  if (form) form.reset();
  resetModalState();
  if (downloadBtn) {
    downloadBtn.focus({ preventScroll: true });
  }
}

function resetModalState() {
  textarea.value = '';
  showError('');
  setStatus('');
  setBusy(false);
}

function setBusy(isBusy) {
  confirmBtn.disabled = isBusy;
  cancelBtn.disabled = isBusy;
  if (cancelTopBtn) cancelTopBtn.disabled = isBusy;
  if (openPageBtn) openPageBtn.disabled = isBusy;
  textarea.readOnly = isBusy;
}

function showError(message) {
  if (errorEl) {
    errorEl.textContent = message || '';
  }
}

function setStatus(message) {
  if (statusEl) {
    statusEl.textContent = message || '';
  }
}

async function onConfirm() {
  const downloadType = overlay?.dataset?.downloadType || 'votes';

  if (downloadType === 'mapping') {
    await onConfirmMapping();
    return;
  }

  // Votes download logic
  const normalisedMnemonic = textarea.value.trim().replace(/\s+/g, ' ');
  if (!normalisedMnemonic) {
    showError('Please enter the 12 words of the secret phrase.');
    textarea.focus();
    return;
  }

  setBusy(true);
  showError('');
  setStatus('Loading libraries…');

  try {
    const { bip39, wordlist, HDKey, sodium } = await loadCryptoModules();
    const Papa = await loadPapaParse();

    if (!bip39.validateMnemonic(normalisedMnemonic, wordlist)) {
      throw new Error('The entered phrase does not match a valid BIP-39 format.');
    }

    setStatus('Retrieving and downloading all votes…');
    const voteFiles = await fetchAllVotes();

    if (voteFiles.length === 0) {
      throw new Error('No votes to decrypt.');
    }

    setStatus(`Decrypting ${voteFiles.length} vote(s)…`);
    const result = await decryptVotes(voteFiles, normalisedMnemonic, { bip39, HDKey, sodium }, Papa, (progress) => {
      setStatus(`Decryption: ${progress}/${voteFiles.length} votes processed…`);
    });

    triggerDownload(result.csv, result.failures, isVotingOpen, result.isStv, result.stvCsv);

    if (result.failures > 0) {
      setStatus(`File downloaded. ${result.failures} line(s) could not be decrypted. Check the "decrypt_error" column.`);
      setBusy(false);
    } else {
      let successMsg = 'File downloaded and decrypted successfully.';
      if (result.isStv && result.stvCsv) {
        successMsg += ' A second file (good_stv compatible format) will download shortly.';
      }
      setStatus(successMsg);
      setTimeout(closeModal, result.isStv ? 1500 : 600);
    }
  } catch (error) {
    console.error('Decrypt CSV error', error);
    showError(error.message || 'Decryption failed.');
    setBusy(false);
    setStatus('');
  }
}

async function loadCryptoModules() {
  if (!loadCryptoModules.promise) {
    loadCryptoModules.promise = (async () => {
      const [{ mnemonicToSeedSync, validateMnemonic }, wordModule, bip32Module, sodiumModule] = await Promise.all([
        import('https://esm.sh/@scure/bip39@1.4.0'),
        import('https://esm.sh/@scure/bip39@1.4.0/wordlists/english'),
        import('https://esm.sh/@scure/bip32@1.3.3'),
        import('https://esm.sh/libsodium-wrappers@0.7.13')
      ]);

      const sodium = sodiumModule.default ?? sodiumModule;
      await sodium.ready;

      return {
        bip39: { mnemonicToSeedSync, validateMnemonic },
        wordlist: wordModule.wordlist,
        HDKey: bip32Module.HDKey,
        sodium
      };
    })();
  }
  return loadCryptoModules.promise;
}

async function loadPapaParse() {
  if (!loadPapaParse.promise) {
    if (window.Papa) {
      loadPapaParse.promise = Promise.resolve(window.Papa);
    } else {
      loadPapaParse.promise = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/papaparse@5.4.1/papaparse.min.js';
        script.async = true;
        script.onload = () => resolve(window.Papa);
        script.onerror = () => reject(new Error('Unable to load CSV library (PapaParse).'));
        document.head.appendChild(script);
      });
    }
  }
  return loadPapaParse.promise;
}

async function fetchAllVotes() {
  const response = await fetch('fetch_all_votes.php', {
    credentials: 'same-origin',
    cache: 'no-store'
  });
  if (!response.ok) {
    throw new Error(`Unable to retrieve votes (code ${response.status}).`);
  }
  return response.json();
}

async function decryptVotes(voteFiles, mnemonic, modules, Papa, progressCallback) {
  const { bip39, HDKey, sodium } = modules;
  const { mnemonicToSeedSync } = bip39;

  // Derive keypair from mnemonic
  const seed = mnemonicToSeedSync(mnemonic);
  const hdkey = HDKey.fromMasterSeed(seed, 'ed25519');
  const derived = hdkey.derive(`m/44'/5353'/0'/0/0`);
  if (!derived.privateKey) {
    throw new Error('Unable to derive private key from secret phrase.');
  }

  const ed25519Keypair = sodium.crypto_sign_seed_keypair(derived.privateKey);
  const x25519Public = sodium.crypto_sign_ed25519_pk_to_curve25519(ed25519Keypair.publicKey);
  const x25519Private = sodium.crypto_sign_ed25519_sk_to_curve25519(ed25519Keypair.privateKey);

  let failures = 0;
  const decryptedVotes = [];
  const allCandidateIds = new Set();
  let isStv = false;

  // Process each vote file
  for (let i = 0; i < voteFiles.length; i++) {
    const voteFile = voteFiles[i];
    if (progressCallback) progressCallback(i + 1);

    try {
      // Use pre-fetched encrypted content
      const encryptedB64 = voteFile.content;

      // Decrypt
      const ciphertext = sodium.from_base64(encryptedB64.trim(), sodium.base64_variants.ORIGINAL);
      const decryptedBytes = sodium.crypto_box_seal_open(ciphertext, x25519Public, x25519Private);
      const voteJson = sodium.to_string(decryptedBytes);

      // Parse JSON vote data
      const voteData = JSON.parse(voteJson);

      // Detect voting method
      if (voteData.rankings && Array.isArray(voteData.rankings)) {
        isStv = true;
        voteData.rankings.forEach(id => allCandidateIds.add(id));
      } else if (voteData.votes) {
        Object.keys(voteData.votes).forEach(id => allCandidateIds.add(id));
      }

      decryptedVotes.push({
        ...voteData,
        decrypt_error: ''
      });
    } catch (err) {
      failures += 1;
      console.error(`Failed to decrypt ${voteFile.filename}:`, err);
      decryptedVotes.push({
        timestamp: '',
        voter_id: '',
        votes: {},
        rankings: [],
        ip: '',
        ua: '',
        decrypt_error: err instanceof Error ? err.message : 'Decryption impossible'
      });
    }
  }

  // Sort candidate IDs alphabetically for consistent column order
  const candidateIds = Array.from(allCandidateIds).sort();

  let csv, stvCsv = null;

  if (isStv) {
    // STV Mode: Build CSV with rankings
    // Full export with metadata
    const maxRankings = Math.max(...decryptedVotes.map(v => (v.rankings || []).length), 0);
    const rankColumns = [];
    for (let i = 1; i <= maxRankings; i++) {
      rankColumns.push(`rank_${i}`);
    }

    const outputRows = decryptedVotes.map((vote) => {
      const row = {
        timestamp: vote.timestamp || '',
        voter_id: vote.voter_id || vote.email || ''
      };

      // Add ranking columns
      const rankings = vote.rankings || [];
      for (let i = 0; i < maxRankings; i++) {
        row[`rank_${i + 1}`] = rankings[i] || '';
      }

      row.ip = vote.ip || '';
      row.ua = vote.ua || '';
      row.decrypt_error = vote.decrypt_error || '';

      return row;
    });

    const columns = ['timestamp', 'voter_id', ...rankColumns, 'ip', 'ua', 'decrypt_error'];
    csv = Papa.unparse(outputRows, { columns });

    // Also generate good_stv compatible format (just rankings, no header)
    const stvRows = decryptedVotes
      .filter(v => v.rankings && v.rankings.length > 0 && !v.decrypt_error)
      .map(v => v.rankings);
    stvCsv = stvRows.map(r => r.join(',')).join('\n');
  } else {
    // Approval Mode: Build CSV with binary columns
    const outputRows = decryptedVotes.map((vote) => {
      const row = {
        timestamp: vote.timestamp || '',
        voter_id: vote.voter_id || vote.email || ''
      };

      // Add binary vote columns (1/0 for each candidate)
      candidateIds.forEach(candidateId => {
        row[candidateId] = vote.votes && vote.votes[candidateId] !== undefined ? vote.votes[candidateId] : 0;
      });

      row.ip = vote.ip || '';
      row.ua = vote.ua || '';
      row.decrypt_error = vote.decrypt_error || '';

      return row;
    });

    const columns = ['timestamp', 'voter_id', ...candidateIds, 'ip', 'ua', 'decrypt_error'];
    csv = Papa.unparse(outputRows, { columns });
  }

  return { csv, failures, isStv, stvCsv };
}

function triggerDownload(csvContent, failures, votingOpen, isStv = false, stvCsv = null) {
  const suffix = new Date().toISOString().slice(0, 10);
  const prefix = isStv ? 'stv-rankings' : 'decrypted-votes';
  const filename = failures > 0 ? `partially-${prefix}-${suffix}.csv` : `${prefix}-${suffix}.csv`;
  let finalCsv = csvContent;
  if (votingOpen) {
    const warningLine = 'WARNING: Voting is still open — these results are provisional.';
    finalCsv = warningLine + '\n' + (csvContent || '');
  }
  const blob = new Blob([finalCsv], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = filename;
  anchor.style.display = 'none';
  document.body.appendChild(anchor);
  anchor.click();
  requestAnimationFrame(() => {
    URL.revokeObjectURL(url);
    anchor.remove();
  });

  // For STV, also offer the good_stv compatible format
  if (isStv && stvCsv) {
    setTimeout(() => {
      const stvFilename = `good-stv-compatible-${suffix}.csv`;
      const stvBlob = new Blob([stvCsv], { type: 'text/csv;charset=utf-8' });
      const stvUrl = URL.createObjectURL(stvBlob);
      const stvAnchor = document.createElement('a');
      stvAnchor.href = stvUrl;
      stvAnchor.download = stvFilename;
      stvAnchor.style.display = 'none';
      document.body.appendChild(stvAnchor);
      stvAnchor.click();
      requestAnimationFrame(() => {
        URL.revokeObjectURL(stvUrl);
        stvAnchor.remove();
      });
    }, 500);
  }
}

/* ===================== VOTER MAPPING DOWNLOAD ===================== */

const mappingForm = document.getElementById('download-mapping-form');

if (mappingForm && overlay && textarea && confirmBtn && cancelBtn) {
  mappingForm.addEventListener('submit', (event) => {
    event.preventDefault();
    resetModalState();
    // Set a flag to indicate we're downloading mapping instead of votes
    overlay.dataset.downloadType = 'mapping';
    openModal();
  });
}

async function onConfirmMapping() {
  const normalisedMnemonic = textarea.value.trim().replace(/\s+/g, ' ');
  if (!normalisedMnemonic) {
    showError('Please enter the 12 words of the secret phrase.');
    textarea.focus();
    return;
  }

  setBusy(true);
  showError('');
  setStatus('Loading libraries…');

  try {
    const { bip39, wordlist, HDKey, sodium } = await loadCryptoModules();
    const Papa = await loadPapaParse();

    if (!bip39.validateMnemonic(normalisedMnemonic, wordlist)) {
      throw new Error('The entered phrase does not match a valid BIP-39 format.');
    }

    setStatus('Retrieving voter mapping…');
    const mappingEntries = await fetchVoterMapping();

    if (mappingEntries.length === 0) {
      throw new Error('No voter mapping entries found.');
    }

    setStatus(`Decrypting ${mappingEntries.length} mapping entry(ies)…`);
    const result = await decryptMapping(mappingEntries, normalisedMnemonic, { bip39, HDKey, sodium }, Papa, (progress) => {
      setStatus(`Decryption: ${progress}/${mappingEntries.length} entries processed…`);
    });

    triggerMappingDownload(result.csv, result.failures);

    if (result.failures > 0) {
      setStatus(`File downloaded. ${result.failures} entry(ies) could not be decrypted.`);
      setBusy(false);
    } else {
      setStatus('Mapping file downloaded and decrypted successfully.');
      setTimeout(closeModal, 600);
    }
  } catch (error) {
    console.error('Decrypt mapping error', error);
    showError(error.message || 'Decryption failed.');
    setBusy(false);
    setStatus('');
  }
}

async function fetchVoterMapping() {
  const response = await fetch('fetch_voter_mapping.php', {
    credentials: 'same-origin',
    cache: 'no-store'
  });
  if (!response.ok) {
    const errorData = await response.json().catch(() => ({}));
    throw new Error(errorData.error || `Unable to retrieve voter mapping (code ${response.status}).`);
  }
  return response.json();
}

async function decryptMapping(mappingEntries, mnemonic, modules, Papa, progressCallback) {
  const { bip39, HDKey, sodium } = modules;
  const { mnemonicToSeedSync } = bip39;

  // Derive keypair from mnemonic (same as for votes)
  const seed = mnemonicToSeedSync(mnemonic);
  const hdkey = HDKey.fromMasterSeed(seed, 'ed25519');
  const derived = hdkey.derive(`m/44'/5353'/0'/0/0`);
  if (!derived.privateKey) {
    throw new Error('Unable to derive private key from secret phrase.');
  }

  const ed25519Keypair = sodium.crypto_sign_seed_keypair(derived.privateKey);
  const x25519Public = sodium.crypto_sign_ed25519_pk_to_curve25519(ed25519Keypair.publicKey);
  const x25519Private = sodium.crypto_sign_ed25519_sk_to_curve25519(ed25519Keypair.privateKey);

  let failures = 0;
  const decryptedMappings = [];

  // Process each mapping entry
  for (let i = 0; i < mappingEntries.length; i++) {
    const entry = mappingEntries[i];
    if (progressCallback) progressCallback(i + 1);

    try {
      // Decrypt
      const ciphertext = sodium.from_base64(entry.content.trim(), sodium.base64_variants.ORIGINAL);
      const decryptedBytes = sodium.crypto_box_seal_open(ciphertext, x25519Public, x25519Private);
      const mappingJson = sodium.to_string(decryptedBytes);

      // Parse JSON mapping data
      const mappingData = JSON.parse(mappingJson);

      decryptedMappings.push({
        voter_id: mappingData.voter_id || '',
        email: mappingData.email || '',
        decrypt_error: ''
      });
    } catch (err) {
      failures += 1;
      console.error(`Failed to decrypt mapping entry ${entry.index}:`, err);
      decryptedMappings.push({
        voter_id: '',
        email: '',
        decrypt_error: err instanceof Error ? err.message : 'Decryption impossible'
      });
    }
  }

  // Build CSV
  const columns = ['voter_id', 'email', 'decrypt_error'];
  const csv = Papa.unparse(decryptedMappings, { columns });

  return { csv, failures };
}

function triggerMappingDownload(csvContent, failures) {
  const suffix = new Date().toISOString().slice(0, 10);
  const filename = failures > 0 ? `partially-decrypted-voter-mapping-${suffix}.csv` : `voter-mapping-${suffix}.csv`;
  const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = filename;
  anchor.style.display = 'none';
  document.body.appendChild(anchor);
  anchor.click();
  requestAnimationFrame(() => {
    URL.revokeObjectURL(url);
    anchor.remove();
  });
}

