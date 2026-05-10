<section class="election-brief flow-step">
  <h2>Welcome to the Election Portal</h2>

  <!-- Condensed Summary (always visible) -->
  <div class="election-brief__summary">
    <p>This is the official space to vote for your community board. Update this text with your organization name, term
      length, and the number of seats available. Your vote determines the team that will set priorities and represent
      the community.</p>
    <p><strong>Who can vote?</strong> List eligibility requirements for your members here.</p>
    <p><strong>Voting period:</strong> Add your start and end dates.</p>
  </div>

  <!-- Read More Button -->
  <button class="election-brief__toggle" aria-expanded="false" aria-controls="election-details">
    <span class="toggle-text">Read More About This Election</span>
    <svg class="toggle-icon" width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
      <path d="M4 6L8 10L12 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
    </svg>
  </button>

  <!-- Expandable Details (hidden by default) -->
  <div class="election-brief__details" id="election-details" aria-hidden="true">
    <div class="election-brief__section">
      <h3>What does this vote decide?</h3>
      <ul>
        <li>Strategic priorities for the next term</li>
        <li>How the organization supports members and partners</li>
        <li>The volunteers who coordinate communication, partnerships, and operations</li>
      </ul>
    </div>

    <div class="election-brief__section">
      <h3>Who are we electing?</h3>
      <ul>
        <li>Number of board members and term length</li>
        <li>Any representation or quota requirements</li>
        <li>Any role, sector, or expertise expectations</li>
      </ul>
    </div>

    <div class="election-brief__section">
      <h3>What will board members commit to?</h3>
      <ul>
        <li>Monthly time commitment for meetings, events, and follow-up</li>
        <li>Active participation and ownership of initiatives</li>
        <li>A collaborative mindset to execute community ideas</li>
      </ul>
    </div>
  </div>
</section>

<script nonce="<?= h(csp_nonce()) ?>">
  (function () {
    const toggle = document.querySelector('.election-brief__toggle');
    const details = document.querySelector('.election-brief__details');

    if (toggle && details) {
      toggle.addEventListener('click', function () {
        const isExpanded = this.getAttribute('aria-expanded') === 'true';

        // Toggle states
        this.setAttribute('aria-expanded', !isExpanded);
        details.setAttribute('aria-hidden', isExpanded);
        details.classList.toggle('is-expanded');

        // Update button text
        const toggleText = this.querySelector('.toggle-text');
        toggleText.textContent = isExpanded ? 'Read More About This Election' : 'Show Less';
      });
    }
  })();
</script>
