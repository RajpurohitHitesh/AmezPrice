/** Admin JavaScript for AmezPrice **/


const POPUPS = {
    SUCCESS: 'success-popup',
    ERROR: 'error-popup',
    OTP: 'otp-popup',
    DELETE_USER: 'delete-user-popup',
    DELETE_LOG: 'delete-log-popup',
    LOG_VIEW: 'log-view-popup'
};

const ENDPOINTS = {
    DELETE_USER: '/admin/delete_user.php',
    LOG_VIEW: '/admin/logs/view.php',
    DELETE_LOG: '/admin/logs/delete.php',
    SETTINGS_API_UI: '/admin/settings/api_ui.php',
    SETTINGS_CATEGORY: '/admin/settings/category.php',
    SETTINGS_TELEGRAM: '/admin/settings/telegram.php',
    SETTINGS_SOCIAL_SECURITY: '/admin/settings/social_security.php',
    SETTINGS_MAIL: '/admin/settings/mail.php',
    PROMOTION_CHANNEL: '/admin/promotion/channel.php',
    PROMOTION_DMS: '/admin/promotion/dms.php',
    PROMOTION_EMAIL: '/admin/promotion/email.php'
};

const getCsrfToken = () => {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
};

// Utility Functions
function showPopup(popupId, content) {
    const popup = document.getElementById(popupId);
    const popupContent = popup.querySelector('.popup-content');
    popupContent.innerHTML = content;
    popup.style.display = 'block';
    document.querySelector('.popup-overlay').style.display = 'block';
    popup.focus(); // Improve accessibility
}

function hidePopup(popupId) {
    document.getElementById(popupId).style.display = 'none';
    document.querySelector('.popup-overlay').style.display = 'none';
}

function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    toast.style.position = 'fixed';
    toast.style.bottom = '20px';
    toast.style.right = '20px';
    toast.style.padding = '12px 24px';
    toast.style.zIndex = '1000';
    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.5s';
        setTimeout(() => toast.remove(), 500);
    }, 3000);
}

function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Popup Management
document.querySelectorAll('.popup-close').forEach(closeBtn => {
    closeBtn.addEventListener('click', () => {
        const popup = closeBtn.closest('.popup');
        hidePopup(popup.id);
    });
});

// Handle keyboard navigation for popups
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.popup').forEach(popup => {
            if (popup.style.display === 'block') {
                hidePopup(popup.id);
            }
        });
    }
});

// Form Validation
function validateForm(formId) {
    const form = document.getElementById(formId);
    const inputs = form.querySelectorAll('input[required], textarea[required], select[required]');
    let valid = true;

    inputs.forEach(input => {
        if (!input.value.trim()) {
            valid = false;
            input.classList.add('error');
            input.setAttribute('aria-invalid', 'true');
        } else {
            input.classList.remove('error');
            input.setAttribute('aria-invalid', 'false');
        }
    });

    return valid;
}

// Sidebar Toggle
document.querySelector('.admin-hamburger')?.addEventListener('click', () => {
    document.querySelector('.admin-sidebar').classList.toggle('active');
});

// User Deletion
async function confirmDeleteUser(userId, email) {
    showPopup(POPUPS.DELETE_USER, `
        <h3>Confirm Deletion</h3>
        <p>Account with email <strong>${email}</strong> will be permanently deleted and cannot be recovered.</p>
        <button class="btn btn-primary" onclick="requestUserDeletionOtp(${userId})">Yes, Send OTP</button>
        <button class="btn btn-secondary" onclick="hidePopup('${POPUPS.DELETE_USER}')">Cancel</button>
    `);
}

async function requestUserDeletionOtp(userId) {
    showLoading();
    const response = await fetch(ENDPOINTS.DELETE_USER, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': getCsrfToken()
        },
        body: JSON.stringify({ user_id: userId, action: 'request_otp' })
    });
    const result = await response.json();
    hideLoading();

    if (result.status === 'success') {
        showPopup(POPUPS.OTP, `
            <h3>Enter OTP</h3>
            <p>OTP sent to your admin email.</p>
            <input type="text" id="otp-input" placeholder="Enter OTP" aria-label="OTP">
            <button class="btn btn-primary" onclick="verifyUserDeletionOtp(${userId})">Submit</button>
            <button class="btn btn-secondary" onclick="hidePopup('${POPUPS.OTP}')">Cancel</button>
            <p id="resend-timer" style="display: none;">Resend in <span id="timer">30</span> seconds</p>
            <a href="#" id="resend-otp" style="display: none;" onclick="requestUserDeletionOtp(${userId})">Resend OTP</a>
        `);
        startResendTimer();
    } else {
        showPopup(POPUPS.ERROR, `<h3>Error</h3><p>${result.message}</p>`);
    }
}

async function verifyUserDeletionOtp(userId) {
    const otp = document.getElementById('otp-input').value;
    showLoading();
    const response = await fetch(ENDPOINTS.DELETE_USER, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': getCsrfToken()
        },
        body: JSON.stringify({ user_id: userId, action: 'verify_otp', otp })
    });
    const result = await response.json();
    hideLoading();

    if (result.status === 'success') {
        showToast('User deleted successfully');
        hidePopup(POPUPS.OTP);
        setTimeout(() => window.location.reload(), 1000);
    } else {
        showPopup(POPUPS.ERROR, `<h3>Error</h3><p>${result.message}</p>`);
    }
}

function startResendTimer() {
    let timeLeft = 30;
    const timerEl = document.getElementById('timer');
    const resendEl = document.getElementById('resend-otp');
    const timerContainer = document.getElementById('resend-timer');
    timerContainer.style.display = 'block';
    resendEl.style.display = 'none';

    const interval = setInterval(() => {
        timeLeft--;
        timerEl.textContent = timeLeft;
        if (timeLeft <= 0) {
            clearInterval(interval);
            timerContainer.style.display = 'none';
            resendEl.style.display = 'block';
        }
    }, 1000);
}

// Log Management
async function viewLog(filename) {
    showLoading();
    const response = await fetch(ENDPOINTS.LOG_VIEW, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': getCsrfToken()
        },
        body: JSON.stringify({ filename })
    });
    const result = await response.json();
    hideLoading();

    if (result.status === 'success') {
        showPopup(POPUPS.LOG_VIEW, `
            <h3>${filename}</h3>
            <pre style="white-space: pre-wrap; max-height: 500px; overflow-y: auto;">${result.content}</pre>
            <button class="btn btn-primary" onclick="downloadLog('${filename}', \`${result.content}\`)">Download</button>
        `);
    } else {
        showPopup(POPUPS.ERROR, `<h3>Error</h3><p>${result.message}</p>`);
    }
}

async function confirmDeleteLog(filename) {
    showPopup(POPUPS.DELETE_LOG, `
        <h3>Confirm Deletion</h3>
        <p>Log file <strong>${filename}</strong> will be permanently deleted and cannot be recovered.</p>
        <button class="btn btn-primary" onclick="deleteLog(['${filename}'])">Yes</button>
        <button class="btn btn-secondary" onclick="hidePopup('${POPUPS.DELETE_LOG}')">Cancel</button>
    `);
}

async function deleteLog(filenames) {
    showLoading();
    const response = await fetch(ENDPOINTS.DELETE_LOG, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': getCsrfToken()
        },
        body: JSON.stringify({ filenames })
    });
    const result = await response.json();
    hideLoading();

    if (result.status === 'success') {
        showToast('Log files deleted successfully');
        hidePopup(POPUPS.DELETE_LOG);
        setTimeout(() => window.location.reload(), 1000);
    } else {
        showPopup(POPUPS.ERROR, `<h3>Error</h3><p>${result.message}</p>`);
    }
}

function downloadLog(filename, content) {
    const blob = new Blob([content], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
}

// Bulk Actions
function initBulkActions() {
    const selectAllCheckbox = document.createElement('input');
    selectAllCheckbox.type = 'checkbox';
    selectAllCheckbox.id = 'select-all';
    selectAllCheckbox.style.marginRight = '8px';

    const bulkDeleteButton = document.createElement('button');
    bulkDeleteButton.className = 'btn btn-delete';
    bulkDeleteButton.textContent = 'Delete Selected';
    bulkDeleteButton.style.marginBottom = '16px';
    bulkDeleteButton.disabled = true;

    const table = document.querySelector('.admin-table table');
    if (table) {
        const headerRow = table.querySelector('thead tr');
        const selectHeader = document.createElement('th');
        selectHeader.appendChild(selectAllCheckbox);
        headerRow.insertBefore(selectHeader, headerRow.firstChild);

        table.parentElement.insertBefore(bulkDeleteButton, table);

        const rows = table.querySelectorAll('tbody tr');
        rows.forEach(row => {
            const selectCell = document.createElement('td');
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'row-checkbox';
            selectCell.appendChild(checkbox);
            row.insertBefore(selectCell, row.firstChild);
        });

        selectAllCheckbox.addEventListener('change', () => {
            const checkboxes = table.querySelectorAll('.row-checkbox');
            checkboxes.forEach(cb => cb.checked = selectAllCheckbox.checked);
            bulkDeleteButton.disabled = !checkboxes.length || !Array.from(checkboxes).some(cb => cb.checked);
        });

        table.addEventListener('change', (e) => {
            if (e.target.classList.contains('row-checkbox')) {
                const checkboxes = table.querySelectorAll('.row-checkbox');
                selectAllCheckbox.checked = Array.from(checkboxes).every(cb => cb.checked);
                bulkDeleteButton.disabled = !checkboxes.length || !Array.from(checkboxes).some(cb => cb.checked);
            }
        });

        bulkDeleteButton.addEventListener('click', async () => {
            const checkboxes = table.querySelectorAll('.row-checkbox:checked');
            const isLogsPage = window.location.pathname.includes('/logs');
            if (isLogsPage) {
                const filenames = Array.from(checkboxes).map(cb => {
                    const row = cb.closest('tr');
                    return row.querySelector('td:first-child').textContent;
                });
                showPopup(POPUPS.DELETE_LOG, `
                    <h3>Confirm Bulk Deletion</h3>
                    <p>Are you sure you want to delete ${filenames.length} log files? These cannot be recovered.</p>
                    <button class="btn btn-primary" onclick="deleteLog([${filenames.map(f => `'${f}'`).join(',')}])">Yes</button>
                    <button class="btn btn-secondary" onclick="hidePopup('${POPUPS.DELETE_LOG}')">Cancel</button>
                `);
            } else {
                const ids = Array.from(checkboxes).map(cb => {
                    const row = cb.closest('tr');
                    const link = row.querySelector('td:first-child a');
                    return link.href.match(/user_id=(\d+)/)[1]; // Extract user_id from link
                });
                showPopup(POPUPS.DELETE_USER, `
                    <h3>Confirm Bulk Deletion</h3>
                    <p>Are you sure you want to delete ${ids.length} users? These cannot be recovered.</p>
                    <button class="btn btn-primary" onclick="requestBulkUserDeletionOtp([${ids.join(',')}])">Yes, Send OTP</button>
                    <button class="btn btn-secondary" onclick="hidePopup('${POPUPS.DELETE_USER}')">Cancel</button>
                `);
            }
        });
    }
}

async function requestBulkUserDeletionOtp(userIds) {
    showLoading();
    const response = await fetch(ENDPOINTS.DELETE_USER, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': getCsrfToken()
        },
        body: JSON.stringify({ user_id: userIds[0], action: 'request_otp' })
    });
    const result = await response.json();
    hideLoading();

    if (result.status === 'success') {
        showPopup(POPUPS.OTP, `
            <h3>Enter OTP</h3>
            <p>OTP sent to your admin email.</p>
            <input type="text" id="otp-input" placeholder="Enter OTP" aria-label="OTP">
            <button class="btn btn-primary" onclick="verifyBulkUserDeletionOtp([${userIds.join(',')}])">Submit</button>
            <button class="btn btn-secondary" onclick="hidePopup('${POPUPS.OTP}')">Cancel</button>
            <p id="resend-timer" style="display: none;">Resend in <span id="timer">30</span> seconds</p>
            <a href="#" id="resend-otp" style="display: none;" onclick="requestBulkUserDeletionOtp([${userIds.join(',')}])">Resend OTP</a>
        `);
        startResendTimer();
    } else {
        showPopup(POPUPS.ERROR, `<h3>Error</h3><p>${result.message}</p>`);
    }
}

async function verifyBulkUserDeletionOtp(userIds) {
    const otp = document.getElementById('otp-input').value;
    showLoading();
    for (const userId of userIds) {
        const response = await fetch(ENDPOINTS.DELETE_USER, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCsrfToken()
            },
            body: JSON.stringify({ user_id: userId, action: 'verify_otp', otp })
        });
        const result = await response.json();

        if (result.status !== 'success') {
            showPopup(POPUPS.ERROR, `<h3>Error</h3><p>Failed to delete user ID ${userId}: ${result.message}</p>`);
            hideLoading();
            return;
        }
    }
    hideLoading();
    showToast('Users deleted successfully');
    hidePopup(POPUPS.OTP);
    setTimeout(() => window.location.reload(), 1000);
}

// Table Sorting
function initTableSorting() {
    const table = document.querySelector('.admin-table table');
    if (!table) return;

    const headers = table.querySelectorAll('th.sortable');
    headers.forEach(header => {
        header.style.cursor = 'pointer';
        header.addEventListener('click', () => {
            const column = header.textContent.toLowerCase().replace(/\s+/g, '_');
            const rows = Array.from(table.querySelectorAll('tbody tr'));
            const isNumeric = ['highest_price', 'lowest_price', 'current_price', 'tracking_users'].includes(column);
            const ascending = !header.classList.contains('asc');

            rows.sort((a, b) => {
                let aValue = a.querySelector(`td:nth-child(${Array.from(header.parentNode.children).indexOf(header) + 1})`).textContent;
                let bValue = b.querySelector(`td:nth-child(${Array.from(header.parentNode.children).indexOf(header) + 1})`).textContent;

                if (isNumeric) {
                    aValue = parseFloat(aValue.replace(/[^\d.]/g, '')) || 0;
                    bValue = parseFloat(bValue.replace(/[^\d.]/g, '')) || 0;
                    return ascending ? aValue - bValue : bValue - aValue;
                } else {
                    return ascending ? aValue.localeCompare(bValue) : bValue.localeCompare(aValue);
                }
            });

            headers.forEach(h => h.classList.remove('asc', 'desc'));
            header.classList.add(ascending ? 'asc' : 'desc');
            header.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');

            const tbody = table.querySelector('tbody');
            tbody.innerHTML = '';
            rows.forEach(row => tbody.appendChild(row));
        });
    });
}

// Search and Filter
function initSearchFilter() {
    const table = document.querySelector('.admin-table table');
    if (!table) return;

    const searchContainer = document.createElement('div');
    searchContainer.style.marginBottom = '16px';
    searchContainer.innerHTML = `
        <input type="text" id="table-search" placeholder="Search..." style="padding: 8px; border-radius: 4px; border: 1px solid #ccc; width: 200px;" aria-label="Search table">
    `;
    table.parentElement.insertBefore(searchContainer, table);

    const searchInput = document.getElementById('table-search');
    searchInput.addEventListener('input', debounce(() => {
        const query = searchInput.value.toLowerCase();
        const rows = table.querySelectorAll('tbody tr');

        rows.forEach(row => {
            const text = Array.from(row.querySelectorAll('td')).map(td => td.textContent.toLowerCase()).join(' ');
            row.style.display = text.includes(query) ? '' : 'none';
        });
    }, 300));
}

// Dynamic Pagination
function initDynamicPagination() {
    const pagination = document.querySelector('.pagination');
    if (!pagination) return;

    const perPageContainer = document.createElement('div');
    perPageContainer.style.marginBottom = '16px';
    perPageContainer.innerHTML = `
        <label for="per-page" style="margin-right: 8px;">Items per page:</label>
        <select id="per-page" aria-label="Items per page">
            <option value="10">10</option>
            <option value="25">25</option>
            <option value="50" selected>50</option>
            <option value="100">100</option>
        </select>
    `;
    pagination.parentElement.insertBefore(perPageContainer, pagination);

    const perPageSelect = document.getElementById('per-page');
    perPageSelect.addEventListener('change', () => {
        const url = new URL(window.location);
        url.searchParams.set('per_page', perPageSelect.value);
        url.searchParams.set('page', '1');
        window.location = url;
    });
}

// Theme Toggle
function initThemeToggle() {
    const themeToggle = document.createElement('button');
    themeToggle.className = 'btn btn-secondary';
    themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
    themeToggle.style.position = 'fixed';
    themeToggle.style.top = '20px';
    themeToggle.style.right = '20px';
    themeToggle.setAttribute('aria-label', 'Toggle theme');
    document.body.appendChild(themeToggle);

    const currentTheme = localStorage.getItem('theme') || 'light';
    document.body.classList.add(currentTheme);

    themeToggle.addEventListener('click', () => {
        const newTheme = document.body.classList.contains('light') ? 'dark' : 'light';
        document.body.classList.remove('light', 'dark');
        document.body.classList.add(newTheme);
        localStorage.setItem('theme', newTheme);
        themeToggle.innerHTML = newTheme === 'light' ? '<i class="fas fa-moon"></i>' : '<i class="fas fa-sun"></i>';
    });
}

// Initialize on Page Load
document.addEventListener('DOMContentLoaded', () => {
    initBulkActions();
    initTableSorting();
    initSearchFilter();
    initDynamicPagination();
    initThemeToggle();

    // Form submissions for settings
    ['amazon-form', 'flipkart-form', 'marketplaces-form', 'social-form', 'security-form', 'mail-form', 'promotion-form', 'dm-promotion-form', 'email-promotion-form'].forEach(formId => {
        const form = document.getElementById(formId);
        if (form) {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                if (!validateForm(formId)) {
                    showPopup(POPUPS.ERROR, '<h3>Error</h3><p>Please fill all required fields</p>');
                    return;
                }

                showLoading();
                const formData = new FormData(form);
                const section = formId.split('-')[0];
                const endpoint = ENDPOINTS[`SETTINGS_${section.toUpperCase()}`] || ENDPOINTS[`PROMOTION_${section.toUpperCase()}`];
                const data = { [section]: Object.fromEntries(formData) };

                if (formId === 'promotion-form' || formId === 'dm-promotion-form') {
                    const imageFile = formData.get('image');
                    if (imageFile && imageFile.size > 0) {
                        const reader = new FileReader();
                        reader.onload = async () => {
                            data[section].image = reader.result;
                            await submitForm(endpoint, data);
                        };
                        reader.readAsDataURL(imageFile);
                        return;
                    }
                }

                await submitForm(endpoint, data);
            });
        }
    });

    // Category form specific handling
    const categoryForm = document.getElementById('category-form');
    if (categoryForm) {
        categoryForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (!validateForm('category-form')) {
                showPopup(POPUPS.ERROR, '<h3>Error</h3><p>Please fill all required fields</p>');
                return;
            }

            showLoading();
            const formData = new FormData(categoryForm);
            const categories = [];
            const headings = formData.getAll('heading[]');
            const cats = formData.getAll('category[]');
            const platforms = formData.getAll('platform[]');

            for (let i = 0; i < headings.length; i++) {
                categories.push({
                    heading: headings[i],
                    category: cats[i],
                    platform: platforms[i]
                });
            }

            const response = await fetch(ENDPOINTS.SETTINGS_CATEGORY, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': getCsrfToken()
                },
                body: JSON.stringify({ categories })
            });
            const result = await response.json();
            hideLoading();

            if (result.status === 'success') {
                showToast('Categories updated successfully');
                hidePopup(POPUPS.SUCCESS);
            } else {
                showPopup(POPUPS.ERROR, `<h3>Error</h3><p>${result.message}</p>`);
            }
        });
    }
});

async function submitForm(endpoint, data) {
    showLoading();
    const response = await fetch(endpoint, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': getCsrfToken()
        },
        body: JSON.stringify(data)
    });
    const result = await response.json();
    hideLoading();

    if (result.status === 'success') {
        showToast('Settings updated successfully');
        hidePopup(POPUPS.SUCCESS);
    } else {
        showPopup(POPUPS.ERROR, `<h3>Error</h3><p>${result.message}</p>`);
    }
}

// Social & Security Settings Functions
function initSocialSecuritySettings() {
    // Handle Social Media form submission
    const socialForm = document.getElementById('social-form');
    if (socialForm) {
        socialForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('/admin/settings/social_security.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    showAlert('success', data.message);
                } else {
                    showAlert('error', data.message);
                }
            })
            .catch(error => {
                showAlert('error', 'An error occurred while saving social media settings');
                console.error('Error:', error);
            });
        });
    }

    // Handle Security form submission
    const securityForm = document.getElementById('security-form');
    if (securityForm) {
        securityForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('/admin/settings/social_security.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    showAlert('success', data.message);
                } else {
                    showAlert('error', data.message);
                }
            })
            .catch(error => {
                showAlert('error', 'An error occurred while saving security settings');
                console.error('Error:', error);
            });
        });
    }
}

// Show alert function for settings
function showAlert(type, message) {
    const alertElement = document.getElementById(type + '-alert');
    if (alertElement) {
        alertElement.textContent = message;
        alertElement.style.display = 'block';
        
        // Hide other alert
        const otherType = type === 'success' ? 'error' : 'success';
        const otherAlert = document.getElementById(otherType + '-alert');
        if (otherAlert) {
            otherAlert.style.display = 'none';
        }
        
        // Auto hide after 5 seconds
        setTimeout(() => {
            alertElement.style.display = 'none';
        }, 5000);
    }
}

// Initialize settings when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    if (window.location.pathname.includes('social_security.php')) {
        initSocialSecuritySettings();
    }
});