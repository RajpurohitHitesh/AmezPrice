// Configuration
const config = {
    carousel: {
        scrollInterval: 3000,
        scrollAmount: window.innerWidth > 768 ? 300 : 200,
        debounceTime: 100,
    },
    popup: {
        animationDuration: 300,
        permissionDelay: 3000,
        errorTimeout: 5000,
    },
    deals: {
        skeletonLoadDelay: 500,
    }
};

// Utility: Debounce function
function debounce(func, wait) {
    let timeout;
    return function (...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
}

// CSRF Token Utilities
function getCsrfToken() {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    if (!token) {
        console.error('CSRF token not found');
        throw new Error('Security token missing');
    }
    return token;
}

async function fetchWithCsrf(url, options = {}) {
    const token = getCsrfToken();
    console.log('Sending CSRF Token:', token);
    
    const headers = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-Token': token,
        'X-Requested-With': 'XMLHttpRequest',
        ...options.headers
    };
    
    try {
        const response = await fetch(url, { 
            ...options, 
            headers,
            credentials: 'same-origin'
        });
        
        console.log('Response status:', response.status);
        console.log('Response headers:', response.headers);
        
        if (!response.ok) {
            const text = await response.text();
            console.error('Error response text:', text);
            throw new Error(`HTTP error! status: ${response.status}, response: ${text}`);
        }
        
        const text = await response.text();
        console.log('Raw response text:', text);
        
        // Check if response is empty
        if (!text.trim()) {
            throw new Error('Empty response from server');
        }
        
        try {
            const jsonResponse = JSON.parse(text);
            console.log('Parsed JSON response:', jsonResponse);
            return jsonResponse;
        } catch (parseError) {
            console.error('JSON parse error:', parseError);
            console.error('Response text that failed to parse:', text.substring(0, 500));
            throw new Error('Server returned invalid JSON. Response: ' + text.substring(0, 100));
        }
    } catch (error) {
        console.error('Fetch error:', error);
        throw error;
    }
}

// Carousel Module
const Carousel = {
    carouselInstances: new Map(), // Track carousel instances and their intervals

    init() {
        document.querySelectorAll('.product-box').forEach(box => {
            const carousel = box.querySelector('.product-carousel');
            if (!carousel) return;

            // Add IntersectionObserver for auto-scroll
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        this.startAutoScroll(carousel);
                    } else {
                        this.stopAutoScroll(carousel);
                    }
                });
            }, { threshold: 0.5 });
            
            observer.observe(carousel);

            // Optimize image loading
            carousel.querySelectorAll('img').forEach(img => {
                img.setAttribute('loading', 'lazy');
                img.setAttribute('decoding', 'async');
            });

            // Event delegation for arrows
            box.addEventListener('click', (e) => {
                const arrow = e.target.closest('.carousel-arrow');
                if (!arrow) return;
                
                const direction = arrow.classList.contains('left') ? -1 : 1;
                this.scroll(carousel, direction);
                this.pauseAutoScroll(carousel);
            });

            carousel.setAttribute('tabindex', '0');
            carousel.setAttribute('aria-label', 'Product carousel');
            
            carousel.addEventListener('keydown', e => {
                if (e.key === 'ArrowLeft') {
                    this.scroll(carousel, -1);
                    this.pauseAutoScroll(carousel);
                } else if (e.key === 'ArrowRight') {
                    this.scroll(carousel, 1);
                    this.pauseAutoScroll(carousel);
                }
            });

            let touchStartX = 0;
            carousel.addEventListener('touchstart', e => {
                touchStartX = e.touches[0].clientX;
                this.stopAutoScroll(carousel);
            }, { passive: true });

            const debouncedTouchMove = debounce(e => {
                const touchEndX = e.touches[0].clientX;
                const diff = touchStartX - touchEndX;
                if (Math.abs(diff) > 50) {
                    this.scroll(carousel, diff > 0 ? 1 : -1);
                    touchStartX = touchEndX;
                }
            }, config.carousel.debounceTime);

            carousel.addEventListener('touchmove', debouncedTouchMove, { passive: true });
            carousel.addEventListener('touchend', () => {
                this.startAutoScroll(carousel);
            }, { passive: true });
        });
    },

    startAutoScroll(carousel) {
        this.stopAutoScroll(carousel);
        const interval = setInterval(() => this.scroll(carousel, 1), config.carousel.scrollInterval);
        this.carouselInstances.set(carousel, interval);
    },

    stopAutoScroll(carousel) {
        const interval = this.carouselInstances.get(carousel);
        if (interval) {
            clearInterval(interval);
            this.carouselInstances.delete(carousel);
        }
    },

    scroll(carousel, direction) {
        carousel.scrollBy({
            left: direction * config.carousel.scrollAmount,
            behavior: 'smooth'
        });
    },

    pauseAutoScroll(carousel) {
        this.stopAutoScroll(carousel);
        setTimeout(() => {
            this.startAutoScroll(carousel);
        }, 5000);
    }
};

// Popup Module
const Popup = {
    init() {
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.popup').forEach(popup => {
                    if (popup.style.display === 'block') {
                        this.hide(popup.id);
                    }
                });
            }
        });
    },

    show(id, content, autoDismiss = false) {
        const popup = document.getElementById(id);
        if (!popup) {
            console.error(`Popup with ID ${id} not found at:`, new Date().toISOString());
            return;
        }
        const overlay = document.querySelector('.popup-overlay');
        const previousActiveElement = document.activeElement;
        popup.querySelector('.popup-content').innerHTML = content;
        popup.style.display = 'block';
        overlay.style.display = 'block';
        popup.style.opacity = '0';
        overlay.style.opacity = '0';
        document.body.style.overflow = 'hidden';

        setTimeout(() => {
            popup.style.transition = `opacity ${config.popup.animationDuration}ms`;
            overlay.style.transition = `opacity ${config.popup.animationDuration}ms`;
            popup.style.opacity = '1';
            overlay.style.opacity = '1';
        }, 10);

        const focusableElements = popup.querySelectorAll('button, [href], input, select, textarea');
        if (focusableElements.length) {
            focusableElements[0].focus();
            popup.addEventListener('keydown', e => {
                if (e.key === 'Tab') {
                    if (e.shiftKey && document.activeElement === focusableElements[0]) {
                        e.preventDefault();
                        focusableElements[focusableElements.length - 1].focus();
                    } else if (!e.shiftKey && document.activeElement === focusableElements[focusableElements.length - 1]) {
                        e.preventDefault();
                        focusableElements[0].focus();
                    }
                }
            });
        }

        if (autoDismiss && id === 'error-popup') {
            setTimeout(() => this.hide(id), config.popup.errorTimeout);
        }

        popup.dataset.previousActive = previousActiveElement ? previousActiveElement.id : '';
    },

    hide(id) {
        const popup = document.getElementById(id);
        if (!popup) {
            console.error(`Popup with ID ${id} not found at:`, new Date().toISOString());
            return;
        }
        const overlay = document.querySelector('.popup-overlay');
        popup.style.opacity = '0';
        overlay.style.opacity = '0';

        setTimeout(() => {
            popup.style.display = 'none';
            overlay.style.display = 'none';
            popup.style.transition = '';
            overlay.style.transition = '';
            document.body.style.overflow = 'auto';

            const previousActiveId = popup.dataset.previousActive;
            if (previousActiveId) {
                const previousElement = document.getElementById(previousActiveId);
                if (previousElement) previousElement.focus();
            }
        }, config.popup.animationDuration);
    }
};

// Push Notification Module
const Push = {
    init() {
        setTimeout(() => {
            const asked = localStorage.getItem('notificationPermissionAsked');
            if (!asked || (Date.now() - parseInt(asked) > 24 * 60 * 60 * 1000)) {
                Popup.show('permission-popup', `
                    <h3>Allow Notifications</h3>
                    <p>We'd like to send you price alerts for your tracked products.</p>
                    <button class="btn btn-primary" onclick="Push.requestPermission(true)">Yes</button>
                    <button class="btn btn-secondary" onclick="Push.dismissPermission()">No</button>
                `);
            }
        }, config.popup.permissionDelay);
    },

    async requestPermission(grant, productId = null) {
        try {
            if (grant && Notification.permission !== 'granted') {
                const permission = await Notification.requestPermission();
                if (permission === 'granted') {
                    await this.subscribe(productId);
                }
            }
            Popup.hide('permission-popup');
            localStorage.setItem('notificationPermissionAsked', Date.now());
        } catch (err) {
            console.error('Push permission error:', err);
            Popup.show('error-popup', `
                <h3>Error</h3>
                <p>Failed to request notification permission: ${err.message}. Retry?</p>
                <button class="btn btn-primary" onclick="Push.requestPermission(true, '${productId}')">Retry</button>
            `, true);
        }
    },

    dismissPermission() {
        Popup.hide('permission-popup');
        localStorage.setItem('notificationPermissionAsked', Date.now());
    },

    async subscribe(productId) {
        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(document.querySelector('meta[name="vapid-public-key"]').content)
            });
            
            await fetchWithCsrf('/push_notification/subscribe.php', {
                method: 'POST',
                body: JSON.stringify({ subscription, product_id: productId })
            });
        } catch (err) {
            console.error('Push subscription error:', err);
            Popup.show('error-popup', `
                <h3>Error</h3>
                <p>Failed to subscribe to push notifications: ${err.message}. Retry?</p>
                <button class="btn btn-primary" onclick="Push.subscribe('${productId}')">Retry</button>
            `, true);
        }
    }
};

// Deals Module
const Deals = {
    init() {
        const grid = document.getElementById('product-grid');
        if (!grid) return;

        grid.style.opacity = 0;
        grid.innerHTML = `
            ${Array(8).fill().map(() => `
                <div class="product-card skeleton">
                    <div class="skeleton-image"></div>
                    <div class="skeleton-title"></div>
                    <div class="skeleton-price"></div>
                    <div class="skeleton-tracking"></div>
                </div>
            `).join('')}
        `;
        setTimeout(() => {
            grid.style.transition = 'opacity 0.3s';
            grid.style.opacity = 1;
            const categorySelect = document.getElementById('category');
            if (categorySelect && categorySelect.value) {
                this.applySort();
            }
        }, config.deals.skeletonLoadDelay);

        document.querySelectorAll('.btn, select').forEach(element => {
            element.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    if (element.tagName === 'SELECT') {
                        this.applySort();
                    } else {
                        element.click();
                    }
                }
            });
        });

        const dealFiltersForm = document.getElementById('deal-filters');
        if (dealFiltersForm) {
            dealFiltersForm.addEventListener('submit', (e) => {
                e.preventDefault();
                const category = document.getElementById('category').value;
                if (category) {
                    window.location.href = '/hotdeals?' + new URLSearchParams({
                        category: category,
                        sort_criteria: document.getElementById('sort_criteria').value,
                        sort_direction: document.getElementById('sort_direction').value,
                        page: 1
                    }).toString();
                }
            });
        }

        this.initPagination();
    },

    applySort() {
        const params = new URLSearchParams({
            category: document.getElementById('category').value,
            sort_criteria: document.getElementById('sort_criteria').value,
            sort_direction: document.getElementById('sort_direction').value,
            page: 1
        });

        fetchWithCsrf('/hotdeals?' + params.toString(), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newGrid = doc.querySelector('#product-grid');
            const newPagination = doc.querySelector('.pagination');
            document.getElementById('product-grid').innerHTML = newGrid.innerHTML;
            document.querySelector('.pagination').innerHTML = newPagination.innerHTML;
            document.getElementById('product-grid').style.opacity = 1;
        })
        .catch(error => {
            console.error('Error fetching sorted deals:', error);
            Popup.show('error-popup', `
                <h3>Error</h3>
                <p>Failed to load deals: ${error.message}. Please try again.</p>
            `, true);
        });
    },

    initPagination() {
        const pagination = document.querySelector('.pagination');
        if (!pagination) return;

        pagination.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', async (e) => {
                e.preventDefault();
                await this.applyPagination(link.href);
            });

            link.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.applyPagination(link.href);
                }
            });
        });
    },

    async applyPagination(url) {
        const grid = document.getElementById('product-grid');
        const pagination = document.querySelector('.pagination');
        const spinner = document.querySelector('.loading-spinner');

        spinner.style.display = 'block';
        grid.style.opacity = '0.5';

        try {
            const response = await fetchWithCsrf(url, {
                headers: {
                    'X-Requested-Fetch': 'XMLHttpRequest'
                }
            });
            const html = await response.text();
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newGrid = doc.querySelector('.product-grid');
            const newPagination = doc.querySelector('.pagination');
            const noDeals = doc.querySelector('.no-deals');

            if (noDeals) {
                grid.innerHTML = noDeals.outerHTML;
            } else {
                grid.innerHTML = newGrid.innerHTML;
            }
            pagination.innerHTML = newPagination.innerHTML;
            grid.style.opacity = '1';
            history.pushState({}, '', url);

            this.initPagination();
        } catch (error) {
            console.error('Error fetching paginated deals:', error);
            Popup.show('error-popup', `
                <h3>Error</h3>
                <p>Failed to load deals: ${error.message}. Please try again.</p>
            `, true);
        } finally {
            spinner.style.display = 'none';
        }
    }
};

// Navbar Module
const Navbar = {
    init() {
        const menuButton = document.querySelector('.navbar-menu');
        if (!menuButton) {
            console.log('Navbar menu button not found');
            return;
        }

        const links = document.querySelector('.navbar-links');
        const social = document.querySelector('.navbar-social');

        if (!links || !social) {
            console.log('Navbar links or social elements not found');
            return;
        }

        menuButton.addEventListener('click', () => {
            links.classList.toggle('active');
            social.classList.toggle('active');
            menuButton.setAttribute('aria-expanded', links.classList.contains('active'));
        });

        if (links) {
            links.querySelectorAll('a').forEach(link => {
                link.addEventListener('click', () => {
                    links.classList.remove('active');
                    social.classList.remove('active');
                    menuButton.setAttribute('aria-expanded', 'false');
                });
            });
        }

        menuButton.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                menuButton.click();
            }
        });
    }
};

// Contact Form Module
const ContactForm = {
    init() {
        const contactForm = document.getElementById('contact-form');
        if (!contactForm) return;

        contactForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(contactForm);
            const data = {
                name: formData.get('name').trim(),
                email: formData.get('email').trim(),
                subject: formData.get('subject').trim(),
                message: formData.get('message').trim()
            };

            // Client-side validation
            if (!data.name || !data.email || !data.subject || !data.message) {
                Popup.show('error-popup', `
                    <h3>Error</h3>
                    <p>All fields are required.</p>
                `, true);
                return;
            }

            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(data.email)) {
                Popup.show('error-popup', `
                    <h3>Error</h3>
                    <p>Please enter a valid email address.</p>
                `, true);
                return;
            }

            try {
                const result = await fetchWithCsrf('/pages/contact-us.php', {
                    method: 'POST',
                    body: JSON.stringify(data)
                });

                if (result.status === 'success') {
                    Popup.show('success-popup', `
                        <h3>Success</h3>
                        <p>${result.message}</p>
                    `);
                    contactForm.reset();
                    contactForm.querySelector('button[type="submit"]').focus();
                } else {
                    throw new Error(result.message || 'Submission failed');
                }
            } catch (err) {
                console.error('Contact form submission error:', err);
                Popup.show('error-popup', `
                    <h3>Error</h3>
                    <p>Failed to send your message: ${err.message}. Please try again later.</p>
                `, true);
            }
        });

        // Accessibility: Ensure form fields are navigable
        const inputs = contactForm.querySelectorAll('input, textarea, button');
        inputs.forEach((input, index) => {
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && input.tagName !== 'BUTTON') {
                    e.preventDefault();
                    const nextIndex = index + 1 < inputs.length ? index + 1 : 0;
                    inputs[nextIndex].focus();
                }
            });
        });
    }
};

// Enhanced Auth Module
const Auth = {
    init() {
        console.log('Auth module initializing at:', new Date().toISOString());
        this.initLoginForm();
        this.initSignupForm();
        this.initForgotPasswordForm();
        this.initOtpForm();
    },

    initLoginForm() {
        const loginForm = document.getElementById('login-form');
        if (!loginForm) {
            console.error('Login form not found');
            return;
        }

        const newForm = loginForm.cloneNode(true);
        loginForm.parentNode.replaceChild(newForm, loginForm);

        document.getElementById('login-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = e.target.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            try {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';

                const formData = new FormData(e.target);
                const data = {
                    identifier: formData.get('identifier').trim(),
                    password: formData.get('password').trim()
                };

                await this.submitForm('/auth/login.php', data, e.target, 'identifier');
            } catch (err) {
                console.error('Login error:', err);
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        });
    },

    initSignupForm() {
        const signupForm = document.getElementById('signup-form');
        if (!signupForm) {
            console.error('Signup form not found');
            return;
        }

        const newForm = signupForm.cloneNode(true);
        signupForm.parentNode.replaceChild(newForm, signupForm);

        document.getElementById('signup-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = e.target.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            try {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';

                const formData = new FormData(e.target);
                const data = {
                    first_name: formData.get('first_name').trim(),
                    last_name: formData.get('last_name').trim(),
                    username: formData.get('username').trim(),
                    email: formData.get('email').trim(),
                    password: formData.get('password').trim()
                };

                await this.submitForm('/auth/signup.php', data, e.target, 'email');
            } catch (err) {
                console.error('Signup error:', err);
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        });
    },

    initForgotPasswordForm() {
        const forgotForm = document.getElementById('forgot-password-form');
        if (!forgotForm) {
            console.error('Forgot password form not found');
            return;
        }

        const newForm = forgotForm.cloneNode(true);
        forgotForm.parentNode.replaceChild(newForm, forgotForm);

        document.getElementById('forgot-password-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = e.target.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            try {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';

                const formData = new FormData(e.target);
                const data = {
                    email: formData.get('email').trim()
                };

                await this.submitForgotPassword('/auth/forgot-password.php', data, e.target);
            } catch (err) {
                console.error('Forgot password error:', err);
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        });
    },

    initOtpForm() {
        const otpForm = document.getElementById('otp-form');
        if (!otpForm) {
            console.error('OTP form not found');
            return;
        }

        const newForm = otpForm.cloneNode(true);
        otpForm.parentNode.replaceChild(newForm, otpForm);

        document.getElementById('otp-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = e.target.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            try {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';

                const formData = new FormData(e.target);
                const data = {
                    email: e.target.dataset.email,
                    otp: formData.get('otp').trim(),
                    new_password: formData.get('new_password').trim(),
                    confirm_password: formData.get('confirm_password').trim()
                };

                await this.submitForgotPassword('/auth/forgot-password.php', data, e.target);
            } catch (err) {
                console.error('OTP submission error:', err);
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        });
    },

    async submitForm(url, data, form, emailKey) {
        try {
            const result = await fetchWithCsrf(url, {
                method: 'POST',
                body: JSON.stringify(data)
            });

            if (result.status === 'success' && result.message === 'OTP sent to your email') {
                this.showOtpPopup(form, data, emailKey);
            } else if (result.status === 'success') {
                window.location.href = result.redirect;
            } else {
                throw new Error(result.message || 'Unknown error occurred');
            }
        } catch (err) {
            console.error('Form submission error:', err);
            Popup.show('error-popup', `
                <h3>Error</h3>
                <p>${err.message || 'Failed to process request. Please try again.'}</p>
            `, true);
            throw err;
        }
    },

async submitForgotPassword(url, data, form) {
    try {
        console.log('Submitting forgot password with data:', data);
        console.log('CSRF Token being sent:', getCsrfToken());
        
        const result = await fetchWithCsrf(url, {
            method: 'POST',
            body: JSON.stringify(data)
        });

        console.log('Forgot password result:', result);

        if (result.status === 'success' && result.message === 'OTP sent to your email') {
            document.getElementById('forgot-password-form').style.display = 'none';
            document.getElementById('otp-form').style.display = 'block';
            document.getElementById('otp-form').dataset.email = data.email;
            this.startResendTimer();
        } else if (result.status === 'success') {
            window.location.href = result.redirect;
        } else {
            throw new Error(result.message || 'Unknown error occurred');
        }
    } catch (err) {
        console.error('Forgot password error:', err);
        Popup.show('error-popup', `
            <h3>Error</h3>
            <p>${err.message || 'Failed to process request. Please try again.'}</p>
        `, true);
        throw err;
    }
},

    showOtpPopup(form, data, emailKey) {
        Popup.show('otp-popup', `
            <h3>Enter OTP</h3>
            <p>OTP sent to your email.</p>
            <input type="text" id="otp-input" placeholder="Enter OTP" aria-label="OTP" class="form-control mb-3">
            <div class="d-flex gap-2">
                <button class="btn btn-primary flex-grow-1" onclick="Auth.submitOtp()">Submit</button>
                <button class="btn btn-secondary" onclick="Popup.hide('otp-popup')">Cancel</button>
            </div>
            <div class="mt-3 text-center">
                <p id="resend-timer" style="display: none;">Resend in <span id="timer">30</span> seconds</p>
                <a href="#" id="resend-otp" style="display: none;" onclick="Auth.resendOtp()">Resend OTP</a>
            </div>
        `);
        
        this.startResendTimer();
        form.dataset.lastData = JSON.stringify(data);
        form.dataset.email = data[emailKey];
        
        setTimeout(() => {
            const otpInput = document.getElementById('otp-input');
            if (otpInput) otpInput.focus();
        }, 100);
    },

// SubmitOtp function
async submitOtp() {
    const otpInput = document.getElementById('otp-input');
    if (!otpInput || !otpInput.value.trim()) {
        Popup.show('error-popup', '<h3>Error</h3><p>Please enter the OTP</p>', true);
        return;
    }

    const form = document.getElementById('login-form') || 
                  document.getElementById('signup-form') || 
                  document.getElementById('otp-form');
    
    if (!form || !form.dataset.email) {
        Popup.show('error-popup', '<h3>Error</h3><p>Form data missing. Please try again.</p>', true);
        return;
    }

    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn?.innerHTML;
    
    try {
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Verifying...';
        }

        const data = form.dataset.lastData ? JSON.parse(form.dataset.lastData) : {};
        data.otp = otpInput.value.trim();
        
        if (form.id === 'otp-form') {
            data.email = form.dataset.email;
            data.new_password = form.querySelector('input[name="new_password"]')?.value.trim();
            data.confirm_password = form.querySelector('input[name="confirm_password"]')?.value.trim();
        }

        const url = form.id === 'login-form' ? '/auth/login.php' : 
                    form.id === 'signup-form' ? '/auth/signup.php' : 
                    '/auth/forgot-password.php';

        console.log('Submitting OTP with data:', data);

        const result = await fetchWithCsrf(url, {
            method: 'POST',
            body: JSON.stringify(data),
            credentials: 'same-origin'
        });

        console.log('OTP submission result:', result);

        if (result.status === 'success') {
            Popup.hide('otp-popup');
            console.log('Login successful, redirecting to:', result.redirect);
            
            // Clear any existing timeouts
            setTimeout(() => {
                if (result.redirect) {
                    console.log('Executing redirect to:', result.redirect);
                    // Use location.replace to prevent back button issues
                    window.location.replace(result.redirect);
                } else {
                    // Fallback redirect
                    console.log('No redirect provided, using fallback');
                    window.location.replace('/user/dashboard.php');
                }
            }, 200);
        } else {
            throw new Error(result.message || 'OTP verification failed');
        }
    } catch (err) {
        console.error('OTP submission error:', err);
        Popup.show('error-popup', `
            <h3>Error</h3>
            <p>${err.message || 'Failed to verify OTP. Please try again.'}</p>
        `, true);
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    }
},

    async resendOtp() {
        const form = document.getElementById('login-form') || 
                     document.getElementById('signup-form') || 
                     document.getElementById('otp-form');
        
        if (!form || !form.dataset.email) {
            Popup.show('error-popup', '<h3>Error</h3><p>Form data missing. Please try again.</p>', true);
            return;
        }

        const resendBtn = document.getElementById('resend-otp');
        const originalText = resendBtn?.innerHTML;
        
        try {
            if (resendBtn) {
                resendBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Sending...';
            }

            const data = form.dataset.lastData ? JSON.parse(form.dataset.lastData) : { email: form.dataset.email };
            const url = form.id === 'login-form' ? '/auth/login.php' : 
                       form.id === 'signup-form' ? '/auth/signup.php' : 
                       '/auth/forgot-password.php';

            const result = await fetchWithCsrf(url, {
                method: 'POST',
                body: JSON.stringify(data)
            });

            if (result.status === 'success') {
                this.startResendTimer();
                Popup.show('otp-popup', document.querySelector('#otp-popup .popup-content').innerHTML);
            } else {
                throw new Error(result.message || 'Failed to resend OTP');
            }
        } catch (err) {
            console.error('Resend OTP error:', err);
            Popup.show('error-popup', `
                <h3>Error</h3>
                <p>${err.message || 'Failed to resend OTP. Please try again.'}</p>
            `, true);
        } finally {
            if (resendBtn) {
                resendBtn.innerHTML = originalText;
            }
        }
    },

    startResendTimer() {
        let timeLeft = 30;
        const timerEl = document.getElementById('timer');
        const resendEl = document.getElementById('resend-otp');
        const timerContainer = document.getElementById('resend-timer');
        
        if (!timerEl || !resendEl || !timerContainer) {
            console.error('Resend timer elements missing');
            return;
        }

        timerContainer.style.display = 'block';
        resendEl.style.display = 'none';

        const interval = setInterval(() => {
            timeLeft--;
            timerEl.textContent = timeLeft;
            
            if (timeLeft <= 0) {
                clearInterval(interval);
                timerContainer.style.display = 'none';
                resendEl.style.display = 'inline-block';
            }
        }, 1000);
    }
};

// Utility Functions
function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    return Uint8Array.from([...rawData].map(char => char.charCodeAt(0)));
}

function initPriceHistoryScroll() {
    if (document.getElementById('price-history-result') && !history.state?.noScroll) {
        const headerHeight = document.querySelector('header')?.offsetHeight || 0;
        window.scrollTo({
            top: document.getElementById('price-history-result').offsetTop - headerHeight,
            behavior: 'smooth'
        });
    }
}

async function toggleFavorite(productId, isFavorite) {
    try {
        await fetchWithCsrf('/user/toggle_favorite.php', {
            method: 'POST',
            body: JSON.stringify({ product_id: productId, is_favorite: !isFavorite })
        });

        const heart = document.querySelector(`i[data-product-id="${productId}"]`) || event.target;
        heart.classList.toggle('favorite');
        heart.style.color = isFavorite ? '#ccc' : '#ff0000';
        
        Popup.show('favorite-popup', `
            <h3>${isFavorite ? 'Removed' : 'Added'} Favorite</h3>
            <p>Product ${isFavorite ? 'removed from' : 'added to'} your favorites.</p>
        `);
        
        if (!isFavorite) {
            const alertData = await fetchWithCsrf('/user/check_alerts.php', {
                method: 'POST',
                body: JSON.stringify({ product_id: productId })
            });
            
            if (alertData.status === 'success' && !alertData.alerts_active) {
                Popup.show('permission-popup', `
                    <h3>Enable Notifications</h3>
                    <p>Would you like to receive price alerts for this product?</p>
                    <button class="btn btn-primary" onclick="Push.requestPermission(true, '${productId}')">Yes</button>
                    <button class="btn btn-secondary" onclick="Push.dismissPermission()">No</button>
                `);
            }
        }
    } catch (err) {
        console.error('Favorite toggle error:', err);
        Popup.show('error-popup', `
            <h3>Error</h3>
            <p>${err.message || 'Failed to update favorite. Please try again.'}</p>
        `, true);
    }
}

function initNavbarAccessibility() {
    const navLinks = document.querySelectorAll('nav a');
    navLinks.forEach(link => {
        link.setAttribute('role', 'menuitem');
        link.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                link.click();
            }
        });
    });
}

// Initialize Modules
document.addEventListener('DOMContentLoaded', () => {
    console.log('Initializing modules at:', new Date().toISOString());
    try {
        Carousel.init();
        Popup.init();
        Push.init();
        Deals.init();
        Navbar.init();
        ContactForm.init();
        Auth.init();
        initPriceHistoryScroll();
        initNavbarAccessibility();
        
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        console.log('Initial CSRF Token:', csrfToken || 'none');
        
        if (!csrfToken) {
            console.error('CSRF token meta tag missing or empty');
            Popup.show('error-popup', `
                <h3>Error</h3>
                <p>CSRF token missing. Please reload the page.</p>
            `, true);
        }
        
        console.log('main.js fully loaded and initialized');
    } catch (err) {
        console.error('Initialization error:', err);
        Popup.show('error-popup', `
            <h3>Error</h3>
            <p>Failed to initialize page: ${err.message}. Please reload the page.</p>
        `, true);
    }
});