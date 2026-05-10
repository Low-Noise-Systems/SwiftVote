import * as bip39 from 'https://esm.sh/@scure/bip39@1.4.0';
import { wordlist } from 'https://esm.sh/@scure/bip39@1.4.0/wordlists/english';
import { HDKey } from 'https://esm.sh/@scure/bip32@1.3.3';

// Load libsodium-wrappers as an ESM dynamic import and wait until ready
const _sodium_mod = await import('https://esm.sh/libsodium-wrappers@0.7.13');
const sodium = _sodium_mod.default ?? _sodium_mod;
await sodium.ready;

const form = document.getElementById('init-form');
const mnemonicSection = document.getElementById('mnemonic-section');
const mnemonicEl = document.getElementById('mnemonic');
const confirmInput = document.getElementById('confirm-mnemonic');
const confirmBtn = document.getElementById('confirm-btn');
const mnemonicError = document.getElementById('mnemonic-error');
const result = document.getElementById('result');
const pubkeySection = document.getElementById('pubkey-section');
const pubkeyB64El = document.getElementById('pubkey-b64');
const pubkeyHexEl = document.getElementById('pubkey-hex');
const pubkeyTimestampEl = document.getElementById('pubkey-timestamp');
const testMnemonicForm = document.getElementById('test-mnemonic-form');
const testMnemonicInput = document.getElementById('test-mnemonic-input');
const testMnemonicBtn = document.getElementById('test-mnemonic-btn');
const testMnemonicResult = document.getElementById('test-mnemonic-result');
const csrfToken = document.body?.dataset?.csrfToken || globalThis.CSRF_TOKEN || '';

let mnemonic, x25519Pub;
let storedPubkeyB64 = null;

function derivePubkeyFingerprint(phrase) {
  const seed = bip39.mnemonicToSeedSync(phrase);
  const hdkey = HDKey.fromMasterSeed(seed, 'ed25519');
  const derived = hdkey.derive(`m/44'/5353'/0'/0/0`);
  if (!derived.privateKey) {
    throw new Error('Unable to derive the private key.');
  }

  const ed25519Keypair = sodium.crypto_sign_seed_keypair(derived.privateKey);
  const x25519 = sodium.crypto_sign_ed25519_pk_to_curve25519(ed25519Keypair.publicKey);

  const pubkeyB64 = sodium.to_base64(x25519, sodium.base64_variants.ORIGINAL);
  const pubkeyHex = Array.from(x25519, (byte) => byte.toString(16).padStart(2, '0')).join('');

  return { pubkeyB64, pubkeyHex };
}

function triggerMnemonicDownload(phrase, fingerprint) {
  try {
    const now = new Date();
    const timestamp = now.toISOString();
    const safeDate = timestamp.replace(/[:.]/g, '-');
    const filename = `secret-phrase-${safeDate}.txt`;
    const lines = [
      'Secret phrase for tallying',
      `Generated on: ${now.toLocaleString('en-US')}`,
      '',
      phrase,
      '',
      'WARNING: Keep this file in a safe place and never share it.'
    ];

    if (fingerprint?.pubkeyB64 || fingerprint?.pubkeyHex) {
      lines.push('', 'Fingerprint linked to this phrase:');
      if (fingerprint.pubkeyB64) {
        lines.push(`- Base64 : ${fingerprint.pubkeyB64}`);
      }
      if (fingerprint.pubkeyHex) {
        lines.push(`- Hexadecimal: ${fingerprint.pubkeyHex}`);
      }
    }

    const blob = new Blob([lines.join('\n')], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    document.body.appendChild(anchor);
    anchor.click();
    requestAnimationFrame(() => {
      anchor.remove();
      URL.revokeObjectURL(url);
    });
  } catch (error) {
    console.error('Mnemonic download failed', error);
  }
}

if (result) {
  result.textContent = 'Verification in progress…';
}

async function refreshExistingPubkey() {
  try {
    const resp = await fetch(`check_pubkey.php`, { cache: 'no-store' });
    const data = await resp.json();

    if (resp.status === 404 || !data.exists) {
      storedPubkeyB64 = null;
      if (pubkeySection) {
        pubkeySection.classList.add('hidden-section');
      }
      if (form) {
        form.classList.remove('hidden-section');
      }
      if (testMnemonicResult) {
        testMnemonicResult.textContent = '';
        testMnemonicResult.classList.remove('status-success', 'status-error');
      }
      if (testMnemonicInput) {
        testMnemonicInput.value = '';
      }
      if (testMnemonicBtn) {
        testMnemonicBtn.disabled = true;
      }
      if (result) {
        result.textContent = 'No tallying code has been registered yet. You can create a new one.';
      }
      return { exists: false };
    }

    if (!resp.ok) {
      throw new Error(data.error || 'Error during code verification.');
    }

    if (pubkeySection) {
      pubkeySection.classList.remove('hidden-section');
    }
    if (form) {
      form.classList.add('hidden-section');
    }
    if (mnemonicSection) {
      mnemonicSection.classList.add('hidden-section');
    }

    if (pubkeyB64El) {
      pubkeyB64El.textContent = data.pubkey_b64 || '';
    }
    if (pubkeyHexEl) {
      pubkeyHexEl.textContent = data.pubkey_hex || '';
    }
    if (pubkeyTimestampEl) {
      const timestamp = data.saved_at ? new Date(data.saved_at) : null;
      pubkeyTimestampEl.textContent = timestamp && !Number.isNaN(timestamp.valueOf())
        ? timestamp.toLocaleString('en-US')
        : 'unknown date';
    }
    storedPubkeyB64 = data.pubkey_b64 || null;
    if (testMnemonicBtn) {
      testMnemonicBtn.disabled = false;
    }
    if (result) {
      result.textContent = 'A tallying code is already ready. Keep your secret phrase safe.';
    }
    return data;
  } catch (error) {
    if (pubkeySection) {
      pubkeySection.classList.add('hidden-section');
    }
    if (result) {
      result.textContent = 'Erreur : ' + error.message;
    }
    return { exists: false, error: error.message };
  }
}

await refreshExistingPubkey();

form.onsubmit = async (e) => {
  e.preventDefault();
  if (result) {
    result.textContent = 'Preparing a new secret phrase…';
  }
  try {
    // Check if pubkey already exists
    const r = await fetch(`check_pubkey.php`, { cache: 'no-store' });
    const data = await r.json();
    if (r.status !== 404 && !r.ok) {
      throw new Error(data.error || 'Unable to verify the code status.');
    }
    if (data.exists) {
      if (result) {
        result.textContent = 'A code is already registered. Use the existing secret phrase.';
      }
      await refreshExistingPubkey();
      return;
    }
    // Generate the mnemonic
    mnemonic = bip39.generateMnemonic(wordlist, 128);
    mnemonicEl.textContent = mnemonic;
    const fingerprint = derivePubkeyFingerprint(mnemonic);
    x25519Pub = fingerprint.pubkeyB64;
    triggerMnemonicDownload(mnemonic, fingerprint);
    mnemonicSection.classList.remove('hidden-section');
    form.classList.add('hidden-section');
  } catch (error) {
    result.textContent = 'Erreur : ' + error.message;
  }
};

confirmBtn.onclick = async () => {
  try {
    if (confirmInput.value.trim() !== mnemonic) {
      mnemonicError.textContent = 'The words do not match.';
      return;
    }
    mnemonicError.textContent = '';
    if (result) {
      result.textContent = 'Recording the code…';
    }
    
    if (!csrfToken) {
      throw new Error('CSRF token not found, reload the page.');
    }

    const fingerprint = derivePubkeyFingerprint(mnemonic);
    x25519Pub = fingerprint.pubkeyB64;

    // POST pubkey with CSRF token
    const resp = await fetch(`save_pubkey.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        pubkey_b64: x25519Pub,
        csrf: csrfToken
      })
    });
    
    const respData = await resp.json();
    
    if (resp.status === 201) {
      if (result) {
        result.textContent = 'The tallying code has been successfully registered.';
      }
      mnemonicSection.classList.add('hidden-section');
      await refreshExistingPubkey();
    } else if (resp.status === 409) {
      if (result) {
        result.textContent = 'A code already exists for these votes.';
      }
      await refreshExistingPubkey();
    } else {
      if (result) {
        result.textContent = 'Erreur : ' + (respData.error || 'Erreur inconnue');
      }
    }
  } catch (error) {
    if (result) {
      result.textContent = 'Erreur : ' + error.message;
    }
    mnemonicError.textContent = '';
  }
};

if (testMnemonicForm) {
  testMnemonicForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!storedPubkeyB64) {
      if (testMnemonicResult) {
        testMnemonicResult.textContent = 'No code is registered to verify the phrase.';
        testMnemonicResult.classList.add('status-error');
        testMnemonicResult.classList.remove('status-success');
      }
      return;
    }

    if (testMnemonicResult) {
      testMnemonicResult.textContent = '';
      testMnemonicResult.classList.remove('status-success', 'status-error');
    }

    const inputValue = (testMnemonicInput?.value || '').trim().replace(/\s+/g, ' ');
    if (!inputValue) {
      if (testMnemonicResult) {
        testMnemonicResult.textContent = 'Please enter the 12 words of the secret phrase.';
        testMnemonicResult.classList.add('status-error');
      }
      return;
    }

    const isValid = bip39.validateMnemonic(inputValue, wordlist);
    if (!isValid) {
      if (testMnemonicResult) {
        testMnemonicResult.textContent = 'The entered phrase does not match the expected format.';
        testMnemonicResult.classList.add('status-error');
      }
      return;
    }

    try {
      const fingerprint = derivePubkeyFingerprint(inputValue);
      const derivedPubkeyB64 = fingerprint.pubkeyB64;

      if (derivedPubkeyB64 === storedPubkeyB64) {
        if (testMnemonicResult) {
          testMnemonicResult.textContent = 'This phrase matches the registered code. You are ready for tallying.';
          testMnemonicResult.classList.add('status-success');
          testMnemonicResult.classList.remove('status-error');
        }
      } else {
        if (testMnemonicResult) {
          testMnemonicResult.textContent = 'This phrase does not match the registered code.';
          testMnemonicResult.classList.add('status-error');
          testMnemonicResult.classList.remove('status-success');
        }
      }
    } catch (error) {
      if (testMnemonicResult) {
        testMnemonicResult.textContent = 'Erreur : ' + error.message;
        testMnemonicResult.classList.add('status-error');
        testMnemonicResult.classList.remove('status-success');
      }
    }
  });
} else if (testMnemonicBtn) {
  // Fallback to enable button when form wrapper is missing
  testMnemonicBtn.disabled = !storedPubkeyB64;
}

// ===================== MAPPING KEY SECTION =====================
// Separate key for voter identity mapping (only if anonymous export is enabled)

const anonymousExportEnabled = document.body?.dataset?.anonymousExport === 'true';

if (anonymousExportEnabled) {
  const mappingKeySection = document.getElementById('mapping-key-section');
  const mappingInitForm = document.getElementById('mapping-init-form');
  const mappingGenerateBtn = document.getElementById('mapping-generate-btn');
  const mappingMnemonicSection = document.getElementById('mapping-mnemonic-section');
  const mappingMnemonicEl = document.getElementById('mapping-mnemonic');
  const mappingConfirmInput = document.getElementById('mapping-confirm-mnemonic');
  const mappingConfirmBtn = document.getElementById('mapping-confirm-btn');
  const mappingMnemonicError = document.getElementById('mapping-mnemonic-error');
  const mappingPubkeySection = document.getElementById('mapping-pubkey-section');
  const mappingPubkeyB64El = document.getElementById('mapping-pubkey-b64');
  const mappingPubkeyHexEl = document.getElementById('mapping-pubkey-hex');
  const mappingPubkeyTimestampEl = document.getElementById('mapping-pubkey-timestamp');
  const testMappingMnemonicForm = document.getElementById('test-mapping-mnemonic-form');
  const testMappingMnemonicInput = document.getElementById('test-mapping-mnemonic-input');
  const testMappingMnemonicBtn = document.getElementById('test-mapping-mnemonic-btn');
  const testMappingMnemonicResult = document.getElementById('test-mapping-mnemonic-result');

  let mappingMnemonic = null;
  let storedMappingPubkeyB64 = null;

  function triggerMappingMnemonicDownload(phrase, fingerprint) {
    try {
      const now = new Date();
      const timestamp = now.toISOString();
      const safeDate = timestamp.replace(/[:.]/g, '-');
      const filename = `mapping-secret-phrase-${safeDate}.txt`;
      const lines = [
        'Secret phrase for VOTER IDENTITY MAPPING',
        '(This is separate from the vote results key)',
        `Generated on: ${now.toLocaleString('en-US')}`,
        '',
        phrase,
        '',
        'WARNING: Keep this file in a safe place.',
        'For best security, give this to a different person than the one',
        'who holds the main vote results key.'
      ];

      if (fingerprint?.pubkeyB64 || fingerprint?.pubkeyHex) {
        lines.push('', 'Fingerprint linked to this phrase:');
        if (fingerprint.pubkeyB64) {
          lines.push(`- Base64 : ${fingerprint.pubkeyB64}`);
        }
        if (fingerprint.pubkeyHex) {
          lines.push(`- Hexadecimal: ${fingerprint.pubkeyHex}`);
        }
      }

      const blob = new Blob([lines.join('\n')], { type: 'text/plain;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = filename;
      document.body.appendChild(anchor);
      anchor.click();
      requestAnimationFrame(() => {
        anchor.remove();
        URL.revokeObjectURL(url);
      });
    } catch (error) {
      console.error('Mapping mnemonic download failed', error);
    }
  }

  async function refreshMappingPubkey() {
    try {
      const resp = await fetch(`check_mapping_pubkey.php`, { cache: 'no-store' });
      const data = await resp.json();

      if (resp.status === 404 || !data.exists) {
        storedMappingPubkeyB64 = null;
        if (mappingPubkeySection) mappingPubkeySection.classList.add('hidden-section');
        if (mappingInitForm) mappingInitForm.classList.remove('hidden-section');
        if (mappingMnemonicSection) mappingMnemonicSection.classList.add('hidden-section');
        if (testMappingMnemonicBtn) testMappingMnemonicBtn.disabled = true;
        return { exists: false };
      }

      if (!resp.ok) {
        throw new Error(data.error || 'Error during mapping key verification.');
      }

      if (mappingPubkeySection) mappingPubkeySection.classList.remove('hidden-section');
      if (mappingInitForm) mappingInitForm.classList.add('hidden-section');
      if (mappingMnemonicSection) mappingMnemonicSection.classList.add('hidden-section');

      if (mappingPubkeyB64El) mappingPubkeyB64El.textContent = data.pubkey_b64 || '';
      if (mappingPubkeyHexEl) mappingPubkeyHexEl.textContent = data.pubkey_hex || '';
      if (mappingPubkeyTimestampEl) {
        const timestamp = data.saved_at ? new Date(data.saved_at) : null;
        mappingPubkeyTimestampEl.textContent = timestamp && !Number.isNaN(timestamp.valueOf())
          ? timestamp.toLocaleString('en-US')
          : 'unknown date';
      }
      storedMappingPubkeyB64 = data.pubkey_b64 || null;
      if (testMappingMnemonicBtn) testMappingMnemonicBtn.disabled = false;
      return data;
    } catch (error) {
      if (mappingPubkeySection) mappingPubkeySection.classList.add('hidden-section');
      return { exists: false, error: error.message };
    }
  }

  // Show mapping key section only after main pubkey is set
  async function initMappingSection() {
    if (!storedPubkeyB64) {
      // Main key not set yet, hide mapping section
      if (mappingKeySection) mappingKeySection.classList.add('hidden-section');
      return;
    }

    if (mappingKeySection) mappingKeySection.classList.remove('hidden-section');
    await refreshMappingPubkey();
  }

  // Generate mapping key button
  if (mappingGenerateBtn) {
    mappingGenerateBtn.addEventListener('click', async () => {
      try {
        // Check if mapping pubkey already exists
        const r = await fetch(`check_mapping_pubkey.php`, { cache: 'no-store' });
        const data = await r.json();
        if (r.status !== 404 && !r.ok) {
          throw new Error(data.error || 'Unable to verify mapping key status.');
        }
        if (data.exists) {
          await refreshMappingPubkey();
          return;
        }
        // Generate the mnemonic
        mappingMnemonic = bip39.generateMnemonic(wordlist, 128);
        if (mappingMnemonicEl) mappingMnemonicEl.textContent = mappingMnemonic;
        const fingerprint = derivePubkeyFingerprint(mappingMnemonic);
        triggerMappingMnemonicDownload(mappingMnemonic, fingerprint);
        if (mappingMnemonicSection) mappingMnemonicSection.classList.remove('hidden-section');
        if (mappingInitForm) mappingInitForm.classList.add('hidden-section');
      } catch (error) {
        console.error('Mapping key generation failed:', error);
      }
    });
  }

  // Confirm mapping key button
  if (mappingConfirmBtn) {
    mappingConfirmBtn.addEventListener('click', async () => {
      try {
        if (!mappingMnemonic || mappingConfirmInput.value.trim() !== mappingMnemonic) {
          if (mappingMnemonicError) {
            mappingMnemonicError.textContent = 'The words do not match.';
            mappingMnemonicError.classList.remove('hidden-section');
          }
          return;
        }
        if (mappingMnemonicError) mappingMnemonicError.classList.add('hidden-section');

        if (!csrfToken) {
          throw new Error('CSRF token not found, reload the page.');
        }

        const fingerprint = derivePubkeyFingerprint(mappingMnemonic);

        const resp = await fetch(`save_mapping_pubkey.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            pubkey_b64: fingerprint.pubkeyB64,
            csrf: csrfToken
          })
        });

        const respData = await resp.json();

        if (resp.status === 201) {
          if (mappingMnemonicSection) mappingMnemonicSection.classList.add('hidden-section');
          await refreshMappingPubkey();
        } else if (resp.status === 409) {
          await refreshMappingPubkey();
        } else {
          if (mappingMnemonicError) {
            mappingMnemonicError.textContent = respData.error || 'Unknown error';
            mappingMnemonicError.classList.remove('hidden-section');
          }
        }
      } catch (error) {
        if (mappingMnemonicError) {
          mappingMnemonicError.textContent = error.message;
          mappingMnemonicError.classList.remove('hidden-section');
        }
      }
    });
  }

  // Test mapping mnemonic form
  if (testMappingMnemonicForm) {
    testMappingMnemonicForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (!storedMappingPubkeyB64) {
        if (testMappingMnemonicResult) {
          testMappingMnemonicResult.textContent = 'No mapping key is registered to verify the phrase.';
          testMappingMnemonicResult.classList.add('status-error');
          testMappingMnemonicResult.classList.remove('status-success');
        }
        return;
      }

      if (testMappingMnemonicResult) {
        testMappingMnemonicResult.textContent = '';
        testMappingMnemonicResult.classList.remove('status-success', 'status-error');
      }

      const inputValue = (testMappingMnemonicInput?.value || '').trim().replace(/\s+/g, ' ');
      if (!inputValue) {
        if (testMappingMnemonicResult) {
          testMappingMnemonicResult.textContent = 'Please enter the 12 words of the mapping phrase.';
          testMappingMnemonicResult.classList.add('status-error');
        }
        return;
      }

      const isValid = bip39.validateMnemonic(inputValue, wordlist);
      if (!isValid) {
        if (testMappingMnemonicResult) {
          testMappingMnemonicResult.textContent = 'The entered phrase does not match the expected format.';
          testMappingMnemonicResult.classList.add('status-error');
        }
        return;
      }

      try {
        const fingerprint = derivePubkeyFingerprint(inputValue);
        const derivedPubkeyB64 = fingerprint.pubkeyB64;

        if (derivedPubkeyB64 === storedMappingPubkeyB64) {
          if (testMappingMnemonicResult) {
            testMappingMnemonicResult.textContent = 'This phrase matches the registered mapping key.';
            testMappingMnemonicResult.classList.add('status-success');
            testMappingMnemonicResult.classList.remove('status-error');
          }
        } else {
          if (testMappingMnemonicResult) {
            testMappingMnemonicResult.textContent = 'This phrase does not match the registered mapping key.';
            testMappingMnemonicResult.classList.add('status-error');
            testMappingMnemonicResult.classList.remove('status-success');
          }
        }
      } catch (error) {
        if (testMappingMnemonicResult) {
          testMappingMnemonicResult.textContent = 'Error: ' + error.message;
          testMappingMnemonicResult.classList.add('status-error');
          testMappingMnemonicResult.classList.remove('status-success');
        }
      }
    });
  }

  // Initialize mapping section after main pubkey check
  await initMappingSection();
} else {
  // Anonymous export not enabled, show disabled message
  const mappingKeyDisabled = document.getElementById('mapping-key-disabled');
  if (mappingKeyDisabled && storedPubkeyB64) {
    mappingKeyDisabled.classList.remove('hidden-section');
  }
}
