function deleteRow(btn) {
  const row = btn.closest('tr');
  const saleId = row.getAttribute('data-sale-id');
  if (confirm('Are you sure you want to delete this sale?')) {
    fetch('delete_sale.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: saleId })
    })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        row.remove();
        // Show message
        const msg = document.getElementById('sale-message');
        msg.textContent = 'Sale deleted successfully.';
        msg.style.display = '';
        setTimeout(() => { msg.style.display = 'none'; }, 3000);
      } else {
        alert('Failed to delete sale.');
      }
    });
  }
}

function editRow(btn) {
  const row = btn.closest('tr');
  row.classList.add('editing');
  row._originalValues = [];
  for (let i = 0; i < row.cells.length - 1; i++) {
    const cell = row.cells[i];
    const value = cell.textContent.trim();
   let inputType = 'text';
let inputValue = value;
if (i === 2) { // Sale Price
  inputType = 'number';
  inputValue = value.replace(/,/g, '').replace(/(\.\d+)?$/, ''); // Remove commas and decimals
  if (!isNaN(parseFloat(inputValue))) {
    inputValue = parseFloat(inputValue);
  } else {
    inputValue = '';
  }
}
if (i === 3) inputType = 'date'; // Sale Date
cell.innerHTML = `<input type="${inputType}" value="${inputValue}" class="border rounded px-2 py-1 w-full" />`;
    // Store the value as it will appear in the input
    row._originalValues.push(value);
  }
  // Change actions to Save and Cancel
  const actionsCell = row.cells[row.cells.length - 1];
  actionsCell.innerHTML = `
    <button class="bg-green-800 text-white px-3 py-1 rounded hover:bg-green-900 mr-2" onclick="saveRow(this)">Save</button>
    <button class="bg-gray-400 text-white px-3 py-1 rounded hover:bg-gray-600" onclick="cancelEditRow(this)">Cancel</button>
  `;
}


function saveRow(btn) {
  const row = btn.closest('tr');
  const saleId = row.getAttribute('data-sale-id');
  const inputs = row.querySelectorAll('input');
  let hasEmpty = false;
  inputs.forEach(input => {
    clearInputError(input);
    if (!input.value) {
      showInputError(input, 'Please fill out this field.');
      if (!hasEmpty) input.focus();
      hasEmpty = true;
    }
  });
  if (hasEmpty) return;

  // Check if any value has changed (normalize for number/date)
  const originalValues = row._originalValues || [];
  let changed = false;
  for (let i = 0; i < inputs.length; i++) {
    let inputVal = (inputs[i].value || '').trim();
    let origVal = (originalValues[i] || '').trim();

    // Normalize number
    if (inputs[i].type === 'number') {
      inputVal = parseFloat(inputVal) || 0;
      origVal = parseFloat(origVal) || 0;
    }
    // Normalize date
    if (inputs[i].type === 'date') {
      // If origVal is in a different format, try to parse and compare as yyyy-mm-dd
      inputVal = inputVal;
      origVal = origVal;
    }

    if (inputVal !== origVal) {
      changed = true;
      break;
    }
  }

  if (!changed) {
    showSaleSuccess('No changes made.', true);
    cancelEditRow(btn);
    return;
  }

  const data = {
    id: saleId,
    property: inputs[0].value,
    buyer: inputs[1].value,
    sale_price: inputs[2].value,
    sale_date: inputs[3].value
  };

  fetch('update_sales.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({sales: [data]})
  })
  .then(res => res.json())
  .then(result => {
    if (result.success) {
      showSaleSuccess('Sale updated successfully!');
      fetchSales();
    } else {
      showSaleSuccess('Save failed: ' + (result.error || 'Unknown error'), true);
    }
  });
}

function cancelEditRow(btn) {
  const row = btn.closest('tr');
  if (row._originalValues) {
    // Restore each cell's value (except the last cell, which is for actions)
    for (let i = 0; i < row._originalValues.length; i++) {
      row.cells[i].innerHTML = row._originalValues[i];
    }
    // Restore actions cell (last cell)
    row.cells[row.cells.length - 1].innerHTML = `
      <button class="bg-green-800 text-white px-3 py-1 rounded hover:bg-green-900 mr-2" onclick="editRow(this)">Edit</button>
      <button class="bg-red-600 text-white px-3 py-1 rounded hover:bg-red-700" onclick="deleteRow(this)">Delete</button>
    `;
    row.classList.remove('editing');
    delete row._originalValues;
  } else {
    fetchSales(); // fallback
  }
}

// DO NOT reset editing state globally here!

function addRow() {
  const tbody = document.getElementById('sales-table-body');
  const tr = document.createElement('tr');
  tr.classList.add('editing');
  tr.innerHTML = `
    <td class="py-2 px-4 bg-gray-50"><input type="text" class="border rounded px-2 py-1 w-full" placeholder="Property"></td>
    <td class="py-2 px-4 bg-gray-50"><input type="text" class="border rounded px-2 py-1 w-full" placeholder="Buyer"></td>
    <td class="py-2 px-4 bg-gray-50"><input type="number" class="border rounded px-2 py-1 w-full" placeholder="Sale Price"></td>
    <td class="py-2 px-4 bg-gray-50"><input type="date" class="border rounded px-2 py-1 w-full"></td>
    <td class="py-2 px-4 bg-gray-50">
      <button class="bg-red-600 text-white px-3 py-1 rounded hover:bg-red-700" onclick="this.closest('tr').remove()">Remove</button>
    </td>
  `;
  tbody.appendChild(tr);
}

function showInputError(input, message) {
  let error = input.parentElement.querySelector('.input-error');
  if (!error) {
    error = document.createElement('div');
    error.className = 'input-error text-red-600 text-xs mt-1';
    input.parentElement.appendChild(error);
  }
  error.textContent = message;
  input.classList.add('border-red-500');
}

function clearInputError(input) {
  let error = input.parentElement.querySelector('.input-error');
  if (error) error.remove();
  input.classList.remove('border-red-500');
}


document.getElementById('add-sale-btn').addEventListener('click', addRow);
document.getElementById('save-all-sales-btn').addEventListener('click', function() {
  const rows = document.querySelectorAll('#sales-table-body tr.editing');
  if (rows.length === 0) {
    // Optionally show a message somewhere else
    return;
  }
  let hasEmpty = false;
  rows.forEach(row => {
    const inputs = row.querySelectorAll('input');
    inputs.forEach(input => {
      clearInputError(input);
      if (!input.value) {
        showInputError(input, 'Please fill out this field.');
        if (!hasEmpty) input.focus();
        hasEmpty = true;
      }
    });
  });
  if (hasEmpty) return;

  const updates = [];
  rows.forEach(row => {
    const saleId = row.getAttribute('data-sale-id') || null;
    const inputs = row.querySelectorAll('input');
    updates.push({
      id: saleId,
      property: inputs[0].value,
      buyer: inputs[1].value,
      sale_price: inputs[2].value,
      sale_date: inputs[3].value
    });
  });

  fetch('update_sales.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({sales: updates})
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      // Show a success message for add or update
      if (updates.some(u => !u.id)) {
        showSaleSuccess('New sale added successfully!');
      } else {
        showSaleSuccess('Sale updated successfully!');
      }
      fetchSales(); // This will reload the table and reset editing state
    } else {
      showSaleSuccess('Save failed: ' + (data.error || 'Unknown error'), true);
    }
  });
});



// Filter functionality
const filterSelect = document.getElementById('filter-select');
const filterInput = document.getElementById('filter-input');
const tableBody = document.getElementById('sales-table-body');

filterSelect.addEventListener('change', function() {
  if (filterSelect.value === 'all') {
    filterInput.style.display = 'none';
    filterInput.value = '';
    filterTable();
  } else {
    filterInput.style.display = '';
    filterInput.value = '';
    filterInput.focus();
    filterTable();
  }
});

filterInput.addEventListener('input', filterTable);

function filterTable() {
  const filterType = filterSelect.value;
  const filterValue = filterInput.value.toLowerCase();
  Array.from(tableBody.rows).forEach(row => {
    // Only filter rows that are not currently being edited (so new rows stay visible)
    if (row.classList.contains('editing')) {
      row.style.display = '';
      return;
    }

    let show = true;
    if (filterType !== 'all' && filterValue) {
      let cellIndex = 0;
      switch (filterType) {
        case 'sale_id':
          cellIndex = 0; // Adjust if you add Sale ID as a column
          break;
        case 'buyer_name':
          cellIndex = 1;
          break;
        case 'property_name':
          cellIndex = 0;
          break;
        case 'sale_date':
          cellIndex = 3;
          break;
        default:
          cellIndex = 0;
      }
      const cell = row.cells[cellIndex];
      const cellText = cell?.textContent.toLowerCase() || '';
      if (cellText.includes(filterValue)) {
        // Highlight the matching part
        const regex = new RegExp(`(${filterValue})`, 'gi');
        cell.innerHTML = cell.textContent.replace(regex, `<span style="background:yellow;">$1</span>`);
        show = true;
      } else {
        show = false;
      }
    }
    row.style.display = show ? '' : 'none';
  });
}

function fetchSales() {
  fetch('sales.php')
    .then(res => res.json())
    .then(data => {
      const tbody = document.getElementById('sales-table-body');
      tbody.innerHTML = '';
      data.forEach(row => {
        tbody.innerHTML += `
  <tr class="odd:bg-white even:bg-gray-50 border-b" data-sale-id="${row.id}">
    <td class="py-2 px-4">${row.property}</td>
    <td class="py-2 px-4">${row.buyer}</td>
    <td class="py-2 px-4">${Number(row.sale_price).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
    <td class="py-2 px-4">${row.sale_date}</td>
    <td class="py-2 px-4">
      <button class="bg-green-800 text-white px-3 py-1 rounded hover:bg-green-900 mr-2" onclick="editRow(this)">Edit</button>
      <button class="bg-red-600 text-white px-3 py-1 rounded hover:bg-red-700" onclick="deleteRow(this)">Delete</button>
    </td>
  </tr>
`;
      });
    });
}


// Call fetchSales on page load
window.onload = fetchSales;

// Sidebar agent info
function loadSidebarAgentInfo() {
  fetch('get_agent_sidebar.php')
    .then(res => res.json())
    .then(data => {
      if (!data.error) {
        document.getElementById('sidebar-agent-name').textContent = data.first_name + ' ' + data.last_name;
        document.getElementById('sidebar-agent-id').textContent = data.id;
        // Update welcome message if you have one
        const welcome = document.getElementById('welcome-message');
        if (welcome) {
          welcome.textContent = 'Welcome, ' + data.first_name + '!';
        }
      }
    });
}
loadSidebarAgentInfo();


// Navigation and profile section toggling + sidebar highlighting
function setActiveProfileTab(tabId) {
  document.getElementById('edit-profile-btn').classList.remove('font-semibold');
  document.getElementById('notifications-btn').classList.remove('font-semibold');
  document.getElementById('password-btn').classList.remove('font-semibold');
  document.getElementById(tabId).classList.add('font-semibold');
}

// When profile-content is shown, set default active tab
document.getElementById('profile-link').addEventListener('click', function(e) {
  e.preventDefault();
  document.getElementById('main-content').style.display = 'none';
  document.getElementById('profile-content').style.display = '';
  setActiveProfileTab('edit-profile-btn');
  showProfileSection('edit-profile');
  loadAgentProfile();
});

document.getElementById('home-link').addEventListener('click', function(e) {
  e.preventDefault();
  document.getElementById('main-content').style.display = '';
  document.getElementById('profile-content').style.display = 'none';
});

function showProfileSection(section) {
  document.getElementById('edit-profile-section').style.display = 'none';
  document.getElementById('notifications-section').style.display = 'none';
  document.getElementById('password-section').style.display = 'none';

  if (section === 'edit-profile') {
    document.getElementById('edit-profile-section').style.display = '';
  } else if (section === 'notifications') {
    document.getElementById('notifications-section').style.display = '';
  } else if (section === 'password') {
    document.getElementById('password-section').style.display = '';
  }
}

// Tab click handlers with highlighting
document.getElementById('edit-profile-btn').addEventListener('click', function() {
  setActiveProfileTab('edit-profile-btn');
  showProfileSection('edit-profile');
  loadAgentProfile();
});
document.getElementById('notifications-btn').addEventListener('click', function() {
  setActiveProfileTab('notifications-btn');
  showProfileSection('notifications');
});
document.getElementById('password-btn').addEventListener('click', function() {
  setActiveProfileTab('password-btn');
  showProfileSection('password');
});

// Load agent profile data into the form
function loadAgentProfile() {
  fetch('get_agent_profile.php')
    .then(res => res.json())
    .then(data => {
      if (!data.error) {
        document.getElementById('first_name').value = data.first_name || '';
        document.getElementById('last_name').value = data.last_name || '';
        document.getElementById('email').value = data.email || '';
        document.getElementById('mobile').value = data.mobile || '';
        document.getElementById('address').value = data.address || '';
        document.getElementById('experience').value = data.experience || 0;
        document.getElementById('total_sales').value = data.total_sales || 0;
        document.getElementById('description').value = data.description || '';
        // Update profile picture preview if available
        if (data.profile_picture && document.getElementById('profile-picture-preview')) {
          document.getElementById('profile-picture-preview').src = data.profile_picture + '?t=' + new Date().getTime();
        }
      }
    });
}


document.addEventListener('DOMContentLoaded', function() {
  
  // Handle profile form submission
  const profileForm = document.getElementById('edit-profile-form');
  if (profileForm) {
    profileForm.addEventListener('submit', function(e) {
      e.preventDefault();
      const form = e.target;
      const formData = new FormData(form);

      const msg = document.getElementById('profile-success-message');
      fetch('update_agent_profile.php', {
  method: 'POST',
  body: formData,
  credentials: 'same-origin' // <-- ADD THIS LINE
})
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          msg.textContent = 'Profile updated successfully';
          msg.style.display = 'block';
          msg.className = 'bg-green-100 text-green-900 px-4 py-2 rounded mb-4';
          setTimeout(() => {
            msg.style.display = 'none';
            window.location.reload(); // Reload to see changes
          }, 1500);
        } else {
          msg.textContent = data.error || 'Update failed';
          msg.style.display = 'block';
          msg.className = 'bg-red-100 text-red-900 px-4 py-2 rounded mb-4';
        }
      })
      .catch(() => {
    msg.textContent = "Error connecting to server.";
    msg.style.display = 'block';
    msg.className = 'bg-red-100 text-red-900 px-4 py-2 rounded mb-4';
});
    });
  }
});


document.querySelector('input[name="profile_picture"]').addEventListener('change', function(event) {
  const file = event.target.files[0];
  if (file) {
    const reader = new FileReader();
    reader.onload = function(e) {
      // Preview in profile form
      const preview = document.getElementById('profile-picture-preview');
      if (preview) {
        preview.src = e.target.result;
        preview.style.display = '';
      }
      // Preview in sidebar
      const sidebarImg = document.getElementById('sidebar-profile-picture');
      if (sidebarImg) {
        sidebarImg.src = e.target.result;
      }
    };
    reader.readAsDataURL(file);
  }
});


function showSaleSuccess(message, isError = false) {
  const msg = document.getElementById('sale-success-message');
  if (!msg) return;
  msg.textContent = message;
  msg.style.display = 'block';
  msg.className = isError
    ? 'bg-red-100 text-red-900 px-4 py-2 rounded mb-4'
    : 'bg-green-100 text-green-900 px-4 py-2 rounded mb-4';
  setTimeout(() => {
    msg.style.display = 'none';
  }, 3000);
}


document.addEventListener('DOMContentLoaded', function() {
  const passwordForm = document.getElementById('change-password-form');
  if (passwordForm) {
    passwordForm.addEventListener('submit', function(e) {
      e.preventDefault();
      const current = passwordForm.elements['current_password'].value.trim();
      const newPass = passwordForm.elements['new_password'].value.trim();
      const confirm = passwordForm.elements['confirm_password'].value.trim();
      const msg = document.getElementById('password-success-message');
      msg.textContent = '';
      msg.className = 'mb-2 text-sm';

      if (!current || !newPass || !confirm) {
        msg.textContent = 'All fields are required.';
        msg.classList.add('text-red-600');
        return;
      }
      if (newPass !== confirm) {
        msg.textContent = 'New passwords do not match.';
        msg.classList.add('text-red-600');
        return;
      }
      if (newPass.length < 6) {
        msg.textContent = 'New password must be at least 6 characters.';
        msg.classList.add('text-red-600');
        return;
      }

      fetch('change_password.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          current_password: current,
          new_password: newPass
        })
      })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          msg.textContent = 'Password updated successfully!';
          msg.classList.remove('text-red-600');
          msg.classList.add('text-green-700');
          passwordForm.reset();
          setTimeout(() => {
            msg.textContent = '';
            window.location.reload(); // Redirect to dashboard after 3 seconds
          }, 1500);
        } else {
          msg.textContent = data.error || 'Password update failed.';
          msg.classList.add('text-red-600');
        }
      })
      .catch(() => {
        msg.textContent = 'Server error. Please try again.';
        msg.classList.add('text-red-600');
      });
    });
  }
});


document.addEventListener('DOMContentLoaded', function() {
  const logoutLink = document.getElementById('logout-link');
  if (logoutLink) {
    logoutLink.addEventListener('click', function(e) {
      if (!confirm('Are you sure you want to logout?')) {
        e.preventDefault();
      }
    });
  }
});
