/**
 * Elegance Salon — In-App Notifications & Real-Time Badge Manager
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        const bellBtn   = document.querySelector('[data-role="notification-bell"]');
        const badge     = document.querySelector('[data-role="notification-badge"]');
        const listBody  = document.querySelector('[data-role="notification-list"]');
        const markAllBtn= document.querySelector('[data-role="mark-all-read"]');
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const appBasePath = document.body.getAttribute('data-base-path') || '';

        if (!bellBtn && !badge) {
            return;
        }

        const apiEndpoint = (appBasePath ? appBasePath + '/' : '') + 'api/notifications.php';

        // Update badge UI
        function updateBadge(count) {
            if (!badge) return;
            const num = parseInt(count, 10) || 0;
            badge.setAttribute('data-count', num);
            if (num > 0) {
                badge.textContent = num > 99 ? '99+' : num;
                badge.style.display = 'inline-block';
            } else {
                badge.textContent = '';
                badge.style.display = 'none';
            }
        }

        // Fetch latest unread count
        async function fetchUnreadCount() {
            try {
                const res = await fetch(apiEndpoint + '?action=get_unread_count');
                if (res.ok) {
                    const data = await res.json();
                    if (data.ok) {
                        updateBadge(data.unread_count);
                    }
                }
            } catch (err) {
                // Silently ignore network failures in background poll
            }
        }

        // Render notifications in dropdown
        function renderNotifications(items) {
            if (!listBody) return;

            if (!items || items.length === 0) {
                listBody.innerHTML = `
                    <div class="notification-empty">
                        <i class="far fa-bell-slash"></i>
                        <p class="small mb-0">No notifications right now.</p>
                    </div>
                `;
                return;
            }

            let html = '';
            items.forEach(item => {
                const unreadClass = item.is_read ? '' : 'unread';
                const iconClass = item.type === 'appointment' ? 'fa-calendar-alt' :
                                  (item.type === 'inventory' ? 'fa-boxes' :
                                  (item.type === 'payment' ? 'fa-credit-card' : 'fa-bell'));

                const targetLink = item.link ? (appBasePath ? appBasePath + '/' : '') + item.link : '#';

                html += `
                    <div class="notification-item ${unreadClass}" data-id="${item.id}" data-link="${targetLink}">
                        <div class="notification-icon-wrap">
                            <i class="fas ${iconClass}"></i>
                        </div>
                        <div class="notification-content">
                            <div class="notification-title">${item.title}</div>
                            <div class="notification-desc">${item.message}</div>
                            <div class="notification-time">
                                <i class="far fa-clock"></i> ${item.time_ago}
                            </div>
                        </div>
                    </div>
                `;
            });

            listBody.innerHTML = html;

            // Attach click listeners to notification items
            listBody.querySelectorAll('.notification-item').forEach(el => {
                el.addEventListener('click', async function(e) {
                    e.preventDefault();
                    const notifId = this.getAttribute('data-id');
                    const link = this.getAttribute('data-link');

                    // Mark as read via AJAX
                    if (this.classList.contains('unread')) {
                        this.classList.remove('unread');
                        try {
                            const formData = new FormData();
                            formData.append('action', 'mark_read');
                            formData.append('id', notifId);
                            formData.append('csrf_token', csrfToken);

                            const res = await fetch(apiEndpoint, {
                                method: 'POST',
                                body: formData
                            });
                            if (res.ok) {
                                const data = await res.json();
                                if (data.ok) {
                                    updateBadge(data.unread_count);
                                }
                            }
                        } catch (err) {
                            console.error('Error marking notification read:', err);
                        }
                    }

                    if (link && link !== '#') {
                        window.location.href = link;
                    }
                });
            });
        }

        // Fetch latest notifications when dropdown opens
        async function loadDropdownContent() {
            if (!listBody) return;
            try {
                listBody.innerHTML = `
                    <div class="text-center py-4 text-muted small">
                        <i class="fas fa-spinner fa-spin me-1"></i> Loading notifications...
                    </div>
                `;
                const res = await fetch(apiEndpoint + '?action=get_latest&limit=6');
                if (res.ok) {
                    const data = await res.json();
                    if (data.ok) {
                        updateBadge(data.unread_count);
                        renderNotifications(data.notifications);
                    }
                }
            } catch (err) {
                listBody.innerHTML = `
                    <div class="notification-empty text-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p class="small mb-0">Failed to load notifications.</p>
                    </div>
                `;
            }
        }

        if (bellBtn) {
            if (window.bootstrap && window.bootstrap.Dropdown) {
                bootstrap.Dropdown.getOrCreateInstance(bellBtn);
                bellBtn.addEventListener('shown.bs.dropdown', function() {
                    loadDropdownContent();
                });
            } else {
                bellBtn.addEventListener('click', function() {
                    loadDropdownContent();
                    const menu = bellBtn.closest('.dropdown')?.querySelector('.dropdown-menu');
                    const isOpen = bellBtn.getAttribute('aria-expanded') === 'true';
                    bellBtn.setAttribute('aria-expanded', String(!isOpen));
                    menu?.classList.toggle('show', !isOpen);
                });
            }
        }

        // Mark All As Read Button
        if (markAllBtn) {
            markAllBtn.addEventListener('click', async function(e) {
                e.preventDefault();
                try {
                    const formData = new FormData();
                    formData.append('action', 'mark_all_read');
                    formData.append('csrf_token', csrfToken);

                    const res = await fetch(apiEndpoint, {
                        method: 'POST',
                        body: formData
                    });
                    if (res.ok) {
                        const data = await res.json();
                        if (data.ok) {
                            updateBadge(0);
                            if (listBody) {
                                listBody.querySelectorAll('.notification-item.unread').forEach(el => {
                                    el.classList.remove('unread');
                                });
                            }
                        }
                    }
                } catch (err) {
                    console.error('Error marking all as read:', err);
                }
            });
        }

        // Periodic background poll every 60 seconds
        setInterval(fetchUnreadCount, 60000);
    });
})();
