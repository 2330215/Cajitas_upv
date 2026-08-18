// Client-side Application Logic
document.addEventListener('DOMContentLoaded', () => {
  
  // State variables
  let attendanceHistory = [];
  let registeredUsers = [];
  let lastUnregisteredId = '';
  
  // Socket Connection
  const socket = io();
  
  // Detect current server IP and port
  const currentHost = window.location.host;
  document.getElementById('server-ip').textContent = `http://${currentHost}`;

  // Clock Setup
  setInterval(updateClock, 1000);
  updateClock();

  function updateClock() {
    const now = new Date();
    const timeString = now.toTimeString().split(' ')[0];
    document.getElementById('clock-display').textContent = timeString;
  }

  // --- NAVIGATION TAB SWITCHING ---
  const navItems = document.querySelectorAll('.nav-item');
  const tabContents = document.querySelectorAll('.tab-content');
  const currentTabTitle = document.getElementById('current-tab-title');
  const currentTabSubtitle = document.getElementById('current-tab-subtitle');

  const tabMeta = {
    dashboard: {
      title: 'Panel de Control',
      subtitle: 'Monitoreo en tiempo real de registros de asistencia'
    },
    users: {
      title: 'Gestión de Usuarios',
      subtitle: 'Registra, edita o elimina credenciales de acceso'
    },
    stats: {
      title: 'Estadísticas e Informes',
      subtitle: 'Distribución de uso, rendimiento y asistencia por equipos'
    }
  };

  navItems.forEach(item => {
    item.addEventListener('click', (e) => {
      e.preventDefault();
      const tabId = item.getAttribute('data-tab');
      
      // Toggle active classes on nav
      navItems.forEach(n => n.classList.remove('active'));
      item.classList.add('active');
      
      // Toggle active classes on sections
      tabContents.forEach(content => {
        content.classList.remove('active');
      });
      document.getElementById(`tab-${tabId}`).classList.add('active');

      // Update titles
      currentTabTitle.textContent = tabMeta[tabId].title;
      currentTabSubtitle.textContent = tabMeta[tabId].subtitle;
      
      // Refresh icon trigger
      lucide.createIcons();
    });
  });

  // --- SOCKET.IO EVENTS ---
  socket.on('initial-data', (data) => {
    attendanceHistory = data.attendance;
    registeredUsers = data.users;
    
    // Find last unregistered scan
    const lastUnreg = attendanceHistory.find(log => !isIdRegistered(log.id));
    if (lastUnreg) {
      lastUnregisteredId = lastUnreg.id;
    }
    
    renderAll();
  });

  socket.on('new-attendance', (record) => {
    // Add to start
    attendanceHistory.unshift(record);
    
    // Keep track of unregistered
    if (record.name === 'Usuario No Registrado') {
      lastUnregisteredId = record.id;
    }
    
    // Play sound / Show Toast
    showAttendanceToast(record);
    
    renderAll();
  });

  socket.on('users-updated', (users) => {
    registeredUsers = users;
    // Update matching names in attendance log
    attendanceHistory.forEach(record => {
      const user = registeredUsers.find(u => u.id === record.id);
      if (user) {
        record.name = user.name;
        record.role = user.role;
        record.team = user.team;
      }
    });
    renderAll();
  });

  socket.on('clear-attendance', () => {
    attendanceHistory = [];
    renderAll();
    showToast('Historial Limpio', 'Se ha vaciado el registro de asistencias', 'info');
  });

  // Check if ID is registered
  function isIdRegistered(id) {
    return registeredUsers.some(u => u.id.toUpperCase() === id.toUpperCase());
  }

  // --- RENDERING ROUTINES ---
  function renderAll() {
    renderKPIs();
    renderLiveFeed();
    renderAttendanceTable();
    renderUsersTable();
    renderStatsTab();
    lucide.createIcons();
  }

  // 1. KPIs
  function renderKPIs() {
    document.getElementById('stat-total-scans').textContent = attendanceHistory.length;
    
    // Active Teams count: unique teams scanning or registered teams scanning
    const activeTeams = new Set();
    attendanceHistory.forEach(log => {
      if (log.team && log.team !== 'Desconocido' && log.team !== 'Sin Equipo') {
        activeTeams.add(log.team);
      }
    });
    document.getElementById('stat-active-teams').textContent = activeTeams.size;

    // Unregistered Scans
    const uniqueUnregistered = new Set();
    attendanceHistory.forEach(log => {
      if (!isIdRegistered(log.id)) {
        uniqueUnregistered.add(log.id);
      }
    });
    document.getElementById('stat-unregistered').textContent = uniqueUnregistered.size;

    // Last access scan representation
    const lastScan = attendanceHistory[0];
    if (lastScan) {
      const timeStr = new Date(lastScan.timestamp).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
      document.getElementById('stat-last-access').textContent = `${lastScan.name} (${timeStr})`;
    } else {
      document.getElementById('stat-last-access').textContent = 'Ninguno';
    }
  }

  // 2. Live Feed
  function renderLiveFeed() {
    const container = document.getElementById('live-feed-container');
    container.innerHTML = '';

    if (attendanceHistory.length === 0) {
      container.innerHTML = `
        <div class="feed-empty-state">
          <i data-lucide="wifi"></i>
          <p>Esperando lecturas de los ESP32...</p>
          <span>Los registros se mostrarán al instante</span>
        </div>`;
      return;
    }

    // Render top 5 logs
    const recentLogs = attendanceHistory.slice(0, 6);
    recentLogs.forEach(log => {
      const isRegistered = isIdRegistered(log.id);
      const logTime = new Date(log.timestamp).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
      
      const typeLower = log.type.toLowerCase();
      const icon = typeLower === 'rfid' ? 'nfc' : 'keypad';
      const typeLabel = typeLower === 'rfid' ? 'RFID' : 'Teclado';
      
      const item = document.createElement('div');
      item.className = `feed-item type-${typeLower} ${!isRegistered ? 'state-unregistered' : ''}`;
      
      let footerHTML = '';
      if (!isRegistered) {
        footerHTML = `
          <div class="feed-footer">
            <span class="text-danger">ID no registrado</span>
            <a class="register-btn-inline" data-id="${log.id}">Registrar</a>
          </div>`;
      } else {
        footerHTML = `
          <div class="feed-footer">
            <span class="text-success">${log.role}</span>
            <span class="text-muted">ID verificado</span>
          </div>`;
      }

      item.innerHTML = `
        <div class="feed-icon-wrapper">
          <i data-lucide="${icon}"></i>
        </div>
        <div class="feed-details">
          <div class="feed-title">
            <h4>${log.name}</h4>
            <span class="feed-time">${logTime}</span>
          </div>
          <div>
            <span class="feed-id-badge"><i data-lucide="hash" style="width:10px;height:10px"></i>${log.id}</span>
            <span class="feed-team-pill">${log.team}</span>
          </div>
          ${footerHTML}
        </div>
      `;
      
      // Attach registration link handler
      const regBtn = item.querySelector('.register-btn-inline');
      if (regBtn) {
        regBtn.addEventListener('click', () => {
          document.getElementById('user-id').value = log.id;
          document.getElementById('user-name').focus();
          // Switch to users tab
          document.querySelector('[data-tab="users"]').click();
        });
      }

      container.appendChild(item);
    });
  }

  // 3. Detailed Attendance Table
  function renderAttendanceTable() {
    const tbody = document.getElementById('attendance-tbody');
    tbody.innerHTML = '';

    const searchQuery = document.getElementById('log-search').value.toLowerCase().trim();
    
    const filteredHistory = attendanceHistory.filter(log => {
      return (
        log.id.toLowerCase().includes(searchQuery) ||
        log.name.toLowerCase().includes(searchQuery) ||
        log.team.toLowerCase().includes(searchQuery) ||
        log.role.toLowerCase().includes(searchQuery) ||
        log.type.toLowerCase().includes(searchQuery)
      );
    });

    if (filteredHistory.length === 0) {
      tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: var(--text-muted);">No se encontraron registros</td></tr>`;
      return;
    }

    filteredHistory.forEach(log => {
      const date = new Date(log.timestamp);
      const timeStr = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
      const dateStr = date.toLocaleDateString([], { day: '2-digit', month: '2-digit' });
      
      const isRegistered = isIdRegistered(log.id);
      const typeClass = log.type.toLowerCase() === 'rfid' ? 'rfid' : 'teclado';
      const typeIcon = log.type.toLowerCase() === 'rfid' ? 'nfc' : 'keypad';

      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td style="font-family: var(--font-mono)">
          <strong>${timeStr}</strong> <span style="font-size:0.75rem; color:var(--text-muted)">(${dateStr})</span>
        </td>
        <td style="font-family: var(--font-mono); font-weight:600">${log.id}</td>
        <td style="font-weight: 500">${log.name}</td>
        <td>${log.role}</td>
        <td>
          <span class="type-indicator ${typeClass}">
            <i data-lucide="${typeIcon}" style="width:12px;height:12px"></i>
            ${log.type}
          </span>
        </td>
        <td>
          <span style="font-weight:600">${log.team}</span> 
          <span style="font-size:0.75rem; color:var(--text-muted); display:block">Emisor: ${log.reportingTeam}</span>
        </td>
        <td>
          <span class="status-pill ${isRegistered ? 'registered' : 'unregistered'}">
            <span class="dot" style="background-color: currentColor; width: 6px; height: 6px"></span>
            ${isRegistered ? 'Verificado' : 'Pendiente'}
          </span>
        </td>
      `;
      tbody.appendChild(tr);
    });
  }

  // 4. Users Table
  function renderUsersTable() {
    const tbody = document.getElementById('users-tbody');
    tbody.innerHTML = '';

    const searchQuery = document.getElementById('user-search').value.toLowerCase().trim();

    const filteredUsers = registeredUsers.filter(u => {
      return (
        u.id.toLowerCase().includes(searchQuery) ||
        u.name.toLowerCase().includes(searchQuery) ||
        u.team.toLowerCase().includes(searchQuery) ||
        u.role.toLowerCase().includes(searchQuery)
      );
    });

    if (filteredUsers.length === 0) {
      tbody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--text-muted);">No hay usuarios que coincidan con la búsqueda</td></tr>`;
      return;
    }

    filteredUsers.forEach(u => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td style="font-family: var(--font-mono); font-weight:600">${u.id}</td>
        <td style="font-weight: 500">${u.name}</td>
        <td><span class="badge badge-primary">${u.team}</span></td>
        <td>${u.role}</td>
        <td class="actions-col">
          <div class="action-buttons-wrapper">
            <button class="btn-icon edit" title="Editar" data-id="${u.id}" data-name="${u.name}" data-team="${u.team}" data-role="${u.role}">
              <i data-lucide="edit-3" style="width:14px;height:14px"></i>
            </button>
            <button class="btn-icon delete" title="Eliminar" data-id="${u.id}">
              <i data-lucide="trash-2" style="width:14px;height:14px"></i>
            </button>
          </div>
        </td>
      `;

      // Wire Actions
      tr.querySelector('.edit').addEventListener('click', (e) => {
        const btn = e.currentTarget;
        document.getElementById('user-id').value = btn.getAttribute('data-id');
        document.getElementById('user-name').value = btn.getAttribute('data-name');
        document.getElementById('user-team').value = btn.getAttribute('data-team');
        document.getElementById('user-role').value = btn.getAttribute('data-role');
        document.getElementById('form-user-title').textContent = 'Editar Usuario';
        document.getElementById('user-name').focus();
      });

      tr.querySelector('.delete').addEventListener('click', async (e) => {
        const id = e.currentTarget.getAttribute('data-id');
        if (confirm(`¿Estás seguro de eliminar el usuario con ID: ${id}?`)) {
          try {
            const res = await fetch(`/api/users/${id}`, { method: 'DELETE' });
            const data = await res.json();
            if (data.success) {
              showToast('Usuario Eliminado', `ID: ${id} fue borrado.`, 'success');
            } else {
              showToast('Error', data.error, 'danger');
            }
          } catch (err) {
            console.error(err);
            showToast('Error', 'No se pudo contactar al servidor', 'danger');
          }
        }
      });

      tbody.appendChild(tr);
    });
  }

  // 5. Statistics Tab
  function renderStatsTab() {
    const teamCharts = document.getElementById('team-charts-container');
    teamCharts.innerHTML = '';

    // Calculate Scans per Team
    const teamCounts = {};
    let totalCount = attendanceHistory.length;

    // Prefill available teams
    const defaultTeams = ['Equipo 1', 'Equipo 2', 'Equipo 3', 'Equipo 4', 'Equipo 5', 'Equipo 6'];
    defaultTeams.forEach(t => teamCounts[t] = 0);
    
    // Add real counts
    attendanceHistory.forEach(log => {
      if (log.team) {
        teamCounts[log.team] = (teamCounts[log.team] || 0) + 1;
      }
    });

    // Find max value to calibrate percentage widths
    const maxVal = Math.max(...Object.values(teamCounts), 1);

    // Sort teams by counts
    const sortedTeams = Object.keys(teamCounts).sort((a, b) => teamCounts[b] - teamCounts[a]);

    sortedTeams.forEach(team => {
      const count = teamCounts[team];
      const percent = (count / maxVal) * 100;
      
      const barContainer = document.createElement('div');
      barContainer.className = 'team-stat-bar-container';
      barContainer.innerHTML = `
        <div class="team-bar-labels">
          <span class="team-bar-name">${team}</span>
          <span class="team-bar-count">${count} registros</span>
        </div>
        <div class="team-bar-outer">
          <div class="team-bar-inner" style="width: ${percent}%"></div>
        </div>
      `;
      teamCharts.appendChild(barContainer);
    });

    // Calculate Type Distribution
    let rfidCount = 0;
    let keypadCount = 0;
    
    attendanceHistory.forEach(log => {
      if (log.type.toLowerCase() === 'rfid') rfidCount++;
      if (log.type.toLowerCase() === 'teclado') keypadCount++;
    });

    const totalTypes = rfidCount + keypadCount;
    const rfidPct = totalTypes > 0 ? Math.round((rfidCount / totalTypes) * 100) : 50;
    const keypadPct = totalTypes > 0 ? Math.round((keypadCount / totalTypes) * 100) : 50;

    document.getElementById('bar-rfid').style.width = `${rfidPct}%`;
    document.getElementById('bar-keypad').style.width = `${keypadPct}%`;
    document.getElementById('pct-rfid').textContent = `${rfidPct}%`;
    document.getElementById('pct-keypad').textContent = `${keypadPct}%`;

    // Calculate Roles Distribution
    const roleCounts = {
      'Estudiante': 0,
      'Profesor': 0,
      'Monitor': 0,
      'Invitado': 0
    };

    attendanceHistory.forEach(log => {
      if (log.role) {
        roleCounts[log.role] = (roleCounts[log.role] || 0) + 1;
      }
    });

    const roleContainer = document.getElementById('role-stat-container');
    roleContainer.innerHTML = '';

    Object.keys(roleCounts).forEach(role => {
      const card = document.createElement('div');
      card.className = 'role-stat-item';
      card.innerHTML = `
        <span class="role-stat-name">${role}</span>
        <span class="role-stat-value">${roleCounts[role]}</span>
      `;
      roleContainer.appendChild(card);
    });
  }

  // --- USER FORM SUBMISSION ---
  const userForm = document.getElementById('user-form');
  userForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const id = document.getElementById('user-id').value.trim();
    const name = document.getElementById('user-name').value.trim();
    const team = document.getElementById('user-team').value;
    const role = document.getElementById('user-role').value;

    try {
      const res = await fetch('/api/users', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, name, team, role })
      });
      const data = await res.json();
      
      if (data.success) {
        showToast('Guardado Exitoso', `Usuario "${name}" registrado correctamente.`, 'success');
        resetUserForm();
      } else {
        showToast('Error', data.error, 'danger');
      }
    } catch (err) {
      console.error(err);
      showToast('Error de Servidor', 'No se pudo guardar la información.', 'danger');
    }
  });

  document.getElementById('btn-reset-form').addEventListener('click', resetUserForm);

  function resetUserForm() {
    userForm.reset();
    document.getElementById('form-user-title').textContent = 'Registrar Nuevo Usuario';
  }

  // "Usar Último ID" Fill button
  document.getElementById('btn-fill-last').addEventListener('click', () => {
    if (lastUnregisteredId) {
      document.getElementById('user-id').value = lastUnregisteredId;
      showToast('ID Cargado', `Copiado UID: ${lastUnregisteredId}`, 'info');
    } else {
      showToast('Sin Pendientes', 'No hay registros recientes sin registrar.', 'warning');
    }
  });

  // Search input event listeners
  document.getElementById('log-search').addEventListener('input', renderAttendanceTable);
  document.getElementById('user-search').addEventListener('input', renderUsersTable);

  // Clear Logs
  document.getElementById('clear-logs-btn').addEventListener('click', async () => {
    if (confirm('¿Estás seguro de que deseas borrar todo el historial de asistencia? Este proceso no se puede deshacer.')) {
      try {
        await fetch('/api/attendance/clear', { method: 'POST' });
      } catch (err) {
        console.error(err);
        showToast('Error', 'No se pudo borrar el historial.', 'danger');
      }
    }
  });

  // --- CSV EXPORTER ---
  document.getElementById('export-csv-btn').addEventListener('click', () => {
    if (attendanceHistory.length === 0) {
      showToast('Sin Registros', 'No hay datos para exportar.', 'warning');
      return;
    }

    let csvContent = "data:text/csv;charset=utf-8,";
    csvContent += "Fecha y Hora,ID Credencial,Nombre,Rol,Tipo Acceso,Equipo,Equipo Emisor\n";

    attendanceHistory.forEach(log => {
      const localTime = new Date(log.timestamp).toLocaleString();
      const row = [
        `"${localTime}"`,
        `"${log.id}"`,
        `"${log.name}"`,
        `"${log.role}"`,
        `"${log.type}"`,
        `"${log.team}"`,
        `"${log.reportingTeam}"`
      ].join(",");
      csvContent += row + "\n";
    });

    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", `asistencias_iot_${new Date().toISOString().slice(0,10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    showToast('Exportación Exitosa', 'El archivo CSV ha sido generado y descargado', 'success');
  });

  // --- TOAST NOTIFICATIONS ---
  function showToast(title, message, type = 'primary') {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    
    let icon = 'info';
    if (type === 'success') icon = 'check-circle-2';
    if (type === 'warning') icon = 'alert-triangle';
    if (type === 'danger') icon = 'x-circle';

    toast.innerHTML = `
      <div class="toast-icon">
        <i data-lucide="${icon}"></i>
      </div>
      <div class="toast-content">
        <h5>${title}</h5>
        <p>${message}</p>
      </div>
      <button class="toast-close"><i data-lucide="x" style="width:14px;height:14px"></i></button>
    `;

    container.appendChild(toast);
    lucide.createIcons();

    // Trigger reflow/animation
    setTimeout(() => toast.classList.add('show'), 10);

    // Auto remove
    const timer = setTimeout(() => {
      toast.classList.remove('show');
      setTimeout(() => toast.remove(), 400);
    }, 4500);

    // Close button
    toast.querySelector('.toast-close').addEventListener('click', () => {
      clearTimeout(timer);
      toast.classList.remove('show');
      setTimeout(() => toast.remove(), 400);
    });
  }

  function showAttendanceToast(record) {
    const isRegistered = isIdRegistered(record.id);
    const title = isRegistered ? 'Asistencia Registrada' : 'ID No Identificado';
    const message = `${record.name} (${record.team}) desde ${record.reportingTeam}`;
    const type = isRegistered ? 'success' : 'warning';
    
    showToast(title, message, type);
    
    // Play light beep sound using HTML5 Audio Synthesis (Web Audio API)
    playChime(isRegistered);
  }

  function playChime(success) {
    try {
      const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
      const osc = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      
      osc.connect(gain);
      gain.connect(audioCtx.destination);
      
      if (success) {
        // High double-beep for success
        osc.frequency.setValueAtTime(880, audioCtx.currentTime); // A5
        gain.gain.setValueAtTime(0.08, audioCtx.currentTime);
        osc.start();
        
        // Stop oscillator at 0.08s, then brief beep at 0.1s
        gain.gain.setValueAtTime(0, audioCtx.currentTime + 0.08);
        osc.frequency.setValueAtTime(1200, audioCtx.currentTime + 0.1);
        gain.gain.setValueAtTime(0.08, audioCtx.currentTime + 0.1);
        gain.gain.setValueAtTime(0, audioCtx.currentTime + 0.22);
        osc.stop(audioCtx.currentTime + 0.25);
      } else {
        // Low double-buzz for warning
        osc.type = 'sawtooth';
        osc.frequency.setValueAtTime(220, audioCtx.currentTime); // A3
        gain.gain.setValueAtTime(0.08, audioCtx.currentTime);
        osc.start();
        gain.gain.setValueAtTime(0, audioCtx.currentTime + 0.15);
        osc.frequency.setValueAtTime(220, audioCtx.currentTime + 0.2);
        gain.gain.setValueAtTime(0.08, audioCtx.currentTime + 0.2);
        gain.gain.setValueAtTime(0, audioCtx.currentTime + 0.35);
        osc.stop(audioCtx.currentTime + 0.4);
      }
    } catch (e) {
      console.warn("Web Audio API not supported/allowed yet:", e);
    }
  }

});
