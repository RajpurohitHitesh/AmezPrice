/*
 * Search functionality for AmezPrice
 */
document.addEventListener('DOMContentLoaded', () => {
    const searchForm = document.querySelector('.search-form');
    const searchInput = document.querySelector('.search-input');
    const searchButton = document.querySelector('.search-button');
    const previewPopup = document.getElementById('search-preview-popup');
    const errorPopup = document.getElementById('search-error-popup');
    const popupOverlay = document.querySelector('.popup-overlay');
    const recentSearchesDropdown = document.createElement('div');
    recentSearchesDropdown.id = 'recent-searches-dropdown';
    recentSearchesDropdown.className = 'recent-searches-dropdown';
    recentSearchesDropdown.style.display = 'none';
    searchForm.appendChild(recentSearchesDropdown);

    // Constants
    const RECENT_SEARCHES_LIMIT = 5;
    const DEBOUNCE_DELAY = 300;
    const ANIMATION_DURATION = 300; // Match main.js popup.animationDuration
    const VALID_URL_PATTERNS = [
        /^https?:\/\/(www\.)?amazon\.(in|co\.uk|com)\/.*\/dp\/[A-Z0-9]{10}/,
        /^https?:\/\/(www\.)?flipkart\.com\/.*pid=[A-Z0-9]+/
    ];
    const SANITIZE_CONFIG = {
        allowedTags: ['img', 'a', 'p', 'h3', 'ul', 'li', 'button'],
        allowedAttributes: {
            'img': ['src', 'alt', 'style'],
            'a': ['href', 'class', 'aria-label'],
            'button': ['class', 'onclick']
        }
    };

    // Load recent searches from localStorage
    let recentSearches = JSON.parse(localStorage.getItem('recentSearches')) || [];

    // Sanitize HTML using sanitize-html
    function sanitizeHTML(str) {
        return window.sanitizeHtml(str, SANITIZE_CONFIG);
    }

    // Fallback HTML encoding
    function encodeHTML(str) {
        return str.replace(/[&<>"']/g, match => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        }[match]));
    }

    // Show popup with sanitized content and fade-in animation
    function showPopup(id, content) {
        const popup = document.getElementById(id);
        const overlay = document.querySelector('.popup-overlay');
        if (!popup || !overlay) {
            console.error(`Popup or overlay not found: ${id}`);
            return;
        }
        const popupContent = popup.querySelector('.popup-content');
        if (!popupContent) {
            console.error(`Popup content not found for: ${id}`);
            return;
        }
        popupContent.innerHTML = sanitizeHTML(content);
        popup.style.display = 'block';
        overlay.style.display = 'block';
        popup.style.opacity = '0';
        overlay.style.opacity = '0';
        document.body.style.overflow = 'hidden';

        // Fade-in animation
        setTimeout(() => {
            popup.style.transition = `opacity ${ANIMATION_DURATION}ms ease`;
            overlay.style.transition = `opacity ${ANIMATION_DURATION}ms ease`;
            popup.style.opacity = '1';
            overlay.style.opacity = '1';
        }, 10);

        // Focus popup for accessibility
        popup.focus();
    }

    // Hide popup with fade-out animation
    function hidePopup(id) {
        const popup = document.getElementById(id);
        const overlay = document.querySelector('.popup-overlay');
        if (popup && overlay) {
            popup.style.opacity = '0';
            overlay.style.opacity = '0';
            setTimeout(() => {
                popup.style.display = 'none';
                overlay.style.display = 'none';
                popup.style.transition = '';
                overlay.style.transition = '';
                document.body.style.overflow = 'auto';
            }, ANIMATION_DURATION);
        }
    }

    // Show recent searches dropdown
    function showRecentSearchesDropdown() {
        renderRecentSearches();
        recentSearchesDropdown.style.display = recentSearches.length ? 'block' : 'none';
    }

    // Hide recent searches dropdown
    function hideRecentSearchesDropdown() {
        recentSearchesDropdown.style.display = 'none';
    }

    // Debounce function to limit API calls
    function debounce(func, delay) {
        let timeout;
        return function (...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), delay);
        };
    }

    // Sanitize and validate URL
    function sanitizeURL(url) {
        const trimmed = url.trim();
        try {
            const parsed = new URL(trimmed.startsWith('http') ? trimmed : `https://${trimmed}`);
            return parsed.href;
        } catch {
            return '';
        }
    }

    // Validate URL for Amazon or Flipkart
    function isValidUrl(url) {
        return url && VALID_URL_PATTERNS.some(pattern => pattern.test(url));
    }

    // Save recent search
    function saveRecentSearch(url, name, websiteUrl) {
        const cleanName = encodeHTML(name);
        const cleanUrl = encodeHTML(url);
        const cleanWebsiteUrl = encodeHTML(websiteUrl);
        recentSearches = recentSearches.filter(item => item.url !== cleanUrl);
        recentSearches.unshift({ url: cleanUrl, name: cleanName, websiteUrl: cleanWebsiteUrl, timestamp: Date.now() });
        if (recentSearches.length > RECENT_SEARCHES_LIMIT) {
            recentSearches.pop();
        }
        localStorage.setItem('recentSearches', JSON.stringify(recentSearches));
    }

    // Render recent searches in dropdown
    function renderRecentSearches() {
        recentSearchesDropdown.innerHTML = recentSearches.length ? '<ul class="recent-searches-list">' : '<p>No recent searches</p>';
        recentSearches.forEach(item => {
            recentSearchesDropdown.innerHTML += `
                <li class="recent-search-item" data-url="${item.websiteUrl}" role="option" tabindex="0" aria-label="View price history for ${item.name}">
                    <span>${item.name.length > 50 ? item.name.substring(0, 47) + '...' : item.name}</span>
                </li>
            `;
        });
        if (recentSearches.length) recentSearchesDropdown.innerHTML += '</ul>';

        // Add click and keyboard events for recent searches
        recentSearchesDropdown.querySelectorAll('.recent-search-item').forEach(item => {
            item.addEventListener('click', () => {
                window.location.href = item.dataset.url;
            });
            item.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    window.location.href = item.dataset.url;
                }
            });
        });
    }

    // Handle search
    const performSearch = debounce(async (url) => {
        const cleanUrl = sanitizeURL(url);
        if (!isValidUrl(cleanUrl)) {
            showPopup('error-popup', `
                <h3>Invalid URL</h3>
                <p>Please enter a valid Amazon or Flipkart product URL. For example:</p>
                <ul>
                    <li>Amazon: https://www.amazon.in/dp/B08L5V9T6R</li>
                    <li>Flipkart: https://www.flipkart.com/pid=SMWFVNKF5X3B6JZM</li>
                </ul>
            `);
            resetLoadingState();
            return;
        }

        // Show loading state
        searchButton.disabled = true;
        searchButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Searching...';
        searchForm.classList.add('loading');
        const overlay = document.createElement('div');
        overlay.className = 'form-loading-overlay';
        searchForm.appendChild(overlay);

        try {
            const response = await fetch('/search/search.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({ url: cleanUrl })
            });

            if (!response.ok) {
                throw new Error(`HTTP error! Status: ${response.status}`);
            }

            const result = await response.json();
            if (result.status === 'success') {
                saveRecentSearch(cleanUrl, result.name, result.website_url);
                showPopup('search-preview-popup', `
                    <img src="${encodeHTML(result.image_path)}" alt="${encodeHTML(result.name)}" style="max-width: 100%; border-radius: 8px;" aria-hidden="true">
                    <h3>${encodeHTML(result.name)}</h3>
                    <p>₹${Number(result.current_price).toLocaleString('en-IN')}</p>
                    <a href="${encodeHTML(result.website_url)}" class="btn btn-primary" aria-label="View price history for ${encodeHTML(result.name)}">View Price History</a>
                `);
            } else {
                showPopup('error-popup', `
                    <h3>Error</h3>
                    <p>${encodeHTML(result.message)}</p>
                `);
            }
        } catch (error) {
            console.error('Search error:', error);
            showPopup('error-popup', `
                <h3>Error</h3>
                <p>Failed to fetch product data: ${encodeHTML(error.message)}</p>
                <button class="btn btn-primary" onclick="performSearch('${encodeHTML(cleanUrl)}')">Retry</button>
            `);
        } finally {
            resetLoadingState(overlay);
        }
    }, DEBOUNCE_DELAY);

    // Reset loading state
    function resetLoadingState(overlay) {
        searchButton.disabled = false;
        searchButton.innerHTML = '<i class="fas fa-magnifying-glass"></i> Search';
        searchForm.classList.remove('loading');
        if (overlay && overlay.parentNode) {
            overlay.parentNode.removeChild(overlay);
        }
    }

    // Form submit handler
    searchForm.addEventListener('submit', (e) => {
        e.preventDefault();
        if (!searchInput || !searchButton) {
            console.error('Search input or button not found');
            return;
        }
        performSearch(searchInput.value.trim());
    });

    // Show recent searches dropdown on input focus
    searchInput.addEventListener('focus', () => {
        showRecentSearchesDropdown();
    });

    // Hide recent searches dropdown on input blur (with delay to allow clicks)
    searchInput.addEventListener('blur', () => {
        setTimeout(() => {
            hideRecentSearchesDropdown();
        }, 200);
    });

    // Clear search input
    searchInput.addEventListener('input', () => {
        if (!searchInput.value) {
            resetLoadingState();
        }
    });

    // Keyboard accessibility
    searchInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !searchButton.disabled) {
            searchForm.dispatchEvent(new Event('submit'));
        }
    });

    // Close popups on overlay click
    popupOverlay.addEventListener('click', () => {
        hidePopup('search-preview-popup');
        hidePopup('error-popup');
        hideRecentSearchesDropdown();
    });
    if (popupOverlay) {
    popupOverlay.addEventListener('click', () => {
        hidePopup('search-preview-popup');
        hidePopup('error-popup');
        hideRecentSearchesDropdown();
    });
    }

    // Focus on input when page loads
    if (searchInput) {
        searchInput.focus();
    }

    // Add ARIA attributes
    if (searchInput) searchInput.setAttribute('aria-label', 'Enter product URL');
    if (searchButton) searchButton.setAttribute('aria-label', 'Search product');
    if (previewPopup) previewPopup.setAttribute('role', 'dialog');
    if (errorPopup) errorPopup.setAttribute('role', 'alertdialog');
    if (recentSearchesDropdown) recentSearchesDropdown.setAttribute('role', 'listbox');
});