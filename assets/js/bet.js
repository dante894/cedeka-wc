// =============================================
// CEDEKA WC — Bet Page JS
// =============================================
(function() {
  const el = document.getElementById('betData');
  if (!el) return;

  const data        = JSON.parse(atob(el.getAttribute('data-json')));
  const takenByMe   = data.takenByMe   || [];
  const allBets     = data.allBets     || {};
  const homeTeam    = data.homeTeam    || '';
  const awayTeam    = data.awayTeam    || '';
  const minBet      = data.minBet      || 1;
  const playersByTeam = data.playersByTeam || {};
  const hasPlayers  = data.hasPlayers  || false;

  let currentTeam = '', currentMinute = 0, currentPlayer = '';

  window.selectTeam = function(team) {
    currentTeam   = team;
    currentMinute = 0;
    currentPlayer = '';

    document.getElementById('teamInput').value   = team;
    document.getElementById('minuteInput').value = '';
    const pi = document.getElementById('playerInput');
    if (pi) pi.value = '';

    // Reset team buttons
    document.querySelectorAll('.team-btn').forEach(b => {
      b.style.borderColor = 'rgba(255,255,255,0.1)';
      b.style.background  = 'var(--bg3)';
      b.style.boxShadow   = 'none';
    });

    // Highlight selected
    const isHome = (team === homeTeam);
    const btn = document.getElementById(isHome ? 'btn-home' : 'btn-away');
    if (btn) {
      btn.style.borderColor = 'var(--gold)';
      btn.style.background  = 'rgba(201,168,76,0.1)';
      btn.style.boxShadow   = '0 0 20px rgba(201,168,76,0.2)';
    }

    buildMinuteGrid(team);
    document.getElementById('minuteSection').style.display = 'block';
    const ps = document.getElementById('playerSection');
    if (ps) ps.style.display = 'none';
    document.getElementById('amountSection').style.display = 'none';
    document.getElementById('sumTeam').textContent = team;
  };

  function buildMinuteGrid(team) {
    const grid = document.getElementById('minuteGrid');
    if (!grid) return;
    grid.innerHTML = '';
    for (let i = 1; i <= 90; i++) {
      const key   = team + '_' + i + '_' + currentPlayer;
      const taken = takenByMe.includes(key) || takenByMe.includes(team + '_' + i + '_');
      const count = allBets[team + '_' + i] || 0;
      const btn   = document.createElement('button');
      btn.type        = 'button';
      btn.className   = 'minute-btn' + (taken ? ' taken' : '');
      btn.textContent = i;
      btn.title = taken ? 'Ya apostaste este minuto' : (count > 0 ? count + ' apuesta(s)' : 'Libre');
      if (count > 0 && !taken) btn.style.opacity = '0.7';
      if (!taken) {
        btn.addEventListener('click', (function(min, b) {
          return function() { selectMinute(min, b); };
        })(i, btn));
      }
      grid.appendChild(btn);
    }
  }

  function selectMinute(min, btn) {
    currentMinute = min;
    document.getElementById('minuteInput').value = min;
    document.querySelectorAll('.minute-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');

    const disp = document.getElementById('selectedMinuteDisplay');
    if (disp) disp.textContent = '✅ Minuto ' + min + ' seleccionado';

    const sumMin = document.getElementById('sumMin');
    if (sumMin) sumMin.textContent = 'Min ' + min;

    if (hasPlayers && document.getElementById('playerSection')) {
      buildPlayerGrid(currentTeam);
      document.getElementById('playerSection').style.display = 'block';
      document.getElementById('amountSection').style.display = 'none';
    } else {
      document.getElementById('amountSection').style.display = 'block';
    }
    updateSummary();
  }

  function buildPlayerGrid(team) {
    const grid    = document.getElementById('playerGrid');
    if (!grid) return;
    const players = playersByTeam[team] || [];
    grid.innerHTML = '';

    if (players.length === 0) {
      grid.innerHTML = '<div style="color:var(--text-dim);font-size:13px;padding:12px;grid-column:1/-1">Sin jugadores cargados para este equipo</div>';
      // Show amount anyway
      setTimeout(() => { document.getElementById('amountSection').style.display = 'block'; }, 100);
      return;
    }

    players.forEach(function(p) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.style.cssText = 'background:var(--bg3);border:2px solid rgba(255,255,255,0.1);border-radius:8px;padding:12px 8px;text-align:center;cursor:pointer;transition:all 0.2s;color:var(--text);width:100%';
      btn.innerHTML =
        (p.jersey_number ? '<div style="font-family:\'Bebas Neue\',sans-serif;font-size:22px;color:var(--gold);line-height:1">' + p.jersey_number + '</div>' : '') +
        '<div style="font-weight:700;font-size:13px;color:#fff;margin-top:4px">' + p.player_name + '</div>' +
        (p.position ? '<div style="font-size:10px;color:var(--text-dim);margin-top:2px">' + p.position + '</div>' : '');

      btn.addEventListener('click', (function(name, b) {
        return function() { selectPlayer(name, b); };
      })(p.player_name, btn));

      grid.appendChild(btn);
    });
  }

  function selectPlayer(name, btn) {
    currentPlayer = name;
    const pi = document.getElementById('playerInput');
    if (pi) pi.value = name;

    document.querySelectorAll('#playerGrid button').forEach(function(b) {
      b.style.borderColor = 'rgba(255,255,255,0.1)';
      b.style.background  = 'var(--bg3)';
      b.style.boxShadow   = 'none';
    });
    btn.style.borderColor = 'var(--gold)';
    btn.style.background  = 'rgba(201,168,76,0.1)';
    btn.style.boxShadow   = '0 0 16px rgba(201,168,76,0.2)';

    const disp = document.getElementById('selectedPlayerDisplay');
    if (disp) disp.textContent = '✅ ' + name + ' seleccionado';

    document.getElementById('amountSection').style.display = 'block';
    updateSummary();
  }

  window.setAmount = function(v) {
    const inp = document.getElementById('amountInput');
    if (inp) inp.value = v;
    updateSummary();
  };

  function updateSummary() {
    const inp = document.getElementById('amountInput');
    const amt = parseFloat((inp && inp.value) || 0);
    const sa  = document.getElementById('sumAmt');
    const sc  = document.getElementById('sumComm');
    if (sa) sa.textContent = amt > 0 ? '₵ ' + amt.toFixed(2) : '—';
    if (sc) sc.textContent = amt > 0 ? '₵ ' + (amt * 0.1).toFixed(2) : '—';
  }

  const amtInp = document.getElementById('amountInput');
  if (amtInp) amtInp.addEventListener('input', updateSummary);

  window.validateBet = function() {
    if (!currentTeam)   { alert('Seleccioná un equipo'); return false; }
    if (!currentMinute) { alert('Seleccioná un minuto'); return false; }
    if (hasPlayers && !currentPlayer) { alert('Seleccioná un jugador'); return false; }
    const amt = parseFloat((document.getElementById('amountInput') || {}).value || 0);
    if (amt < minBet) { alert('Monto mínimo ₵ ' + minBet); return false; }
    return true;
  };

})();

// Auto-bind team buttons via data-team attribute
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.team-btn[data-team]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      window.selectTeam(this.getAttribute('data-team'));
    });
  });
});