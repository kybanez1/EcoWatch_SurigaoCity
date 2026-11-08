// EcoWatch shared JavaScript (loaded on every page).

// ---------- Modals ----------
// The element that had the focus before a modal opened, so the focus can go back when it closes.
var elementBeforeModal = null;

function getFocusableItems(modal) {
    return modal.querySelectorAll('a[href], button:not([disabled]), input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])');
}

function openModal(modalId) {
    var modal = document.getElementById(modalId);
    if (!modal) {
        return;
    }
    elementBeforeModal = document.activeElement;
    modal.classList.add('open');

    // Put the focus on the first form field (or the close button).
    var items = getFocusableItems(modal.querySelector('.modal'));
    var firstField = modal.querySelector('input:not([type="hidden"]), select, textarea');
    if (firstField) {
        firstField.focus();
    } else if (items.length > 0) {
        items[0].focus();
    }
}

function closeModal(modalElement) {
    modalElement.classList.remove('open');
    if (elementBeforeModal && document.body.contains(elementBeforeModal)) {
        elementBeforeModal.focus();
    }
    elementBeforeModal = null;
}

// Give every modal the right accessibility attributes.
function prepareModals() {
    var modals = document.querySelectorAll('.modal-bg');
    for (var i = 0; i < modals.length; i++) {
        var modal = modals[i];
        var heading = modal.querySelector('h2');
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        if (heading) {
            if (!heading.id) {
                heading.id = (modal.id || 'modal' + i) + '-title';
            }
            modal.setAttribute('aria-labelledby', heading.id);
        }
        var closeButton = modal.querySelector('.close');
        if (closeButton && !closeButton.getAttribute('aria-label')) {
            closeButton.setAttribute('aria-label', 'Close');
        }
    }

    // Icon buttons that only have a title get the same text as accessible name.
    var buttons = document.querySelectorAll('button[title], a[title]');
    for (var j = 0; j < buttons.length; j++) {
        if (!buttons[j].getAttribute('aria-label')) {
            buttons[j].setAttribute('aria-label', buttons[j].getAttribute('title'));
        }
    }

    // A modal that the server opened (after a validation error) gets the focus too.
    var openModals = document.querySelectorAll('.modal-bg.open');
    if (openModals.length > 0) {
        var firstField = openModals[0].querySelector('input:not([type="hidden"]), select, textarea');
        if (firstField) {
            firstField.focus();
        }
    }
}
prepareModals();

// Keyboard: Escape closes the open modal, Tab stays inside the modal.
document.addEventListener('keydown', function (event) {
    var openModalElement = document.querySelector('.modal-bg.open');
    if (!openModalElement) {
        return;
    }
    if (event.key === 'Escape') {
        closeModal(openModalElement);
        return;
    }
    if (event.key === 'Tab') {
        var items = getFocusableItems(openModalElement);
        if (items.length === 0) {
            return;
        }
        var firstItem = items[0];
        var lastItem = items[items.length - 1];
        if (event.shiftKey && document.activeElement === firstItem) {
            event.preventDefault();
            lastItem.focus();
        } else if (!event.shiftKey && document.activeElement === lastItem) {
            event.preventDefault();
            firstItem.focus();
        }
    }
});

// ---------- Page data from Blade ----------
// Blade puts the data in <script type="application/json" id="page-data">.
function readPageData() {
    var dataElement = document.getElementById('page-data');
    if (!dataElement) {
        return {};
    }
    // A damaged data block must not stop the rest of the page.
    try {
        return JSON.parse(dataElement.textContent) || {};
    } catch (error) {
        return {};
    }
}

// ---------- Click handling ----------
document.addEventListener('click', function (event) {
    var clicked = event.target;

    // Click on the dark background closes the modal.
    if (clicked.classList.contains('modal-bg')) {
        closeModal(clicked);
        return;
    }

    // Buttons with data-open="modalId" open a modal.
    var openButton = clicked.closest('[data-open]');
    if (openButton) {
        event.preventDefault();
        openModal(openButton.dataset.open);
        return;
    }

    // Buttons with data-close close the modal they are inside.
    var closeButton = clicked.closest('[data-close]');
    if (closeButton) {
        closeModal(closeButton.closest('.modal-bg'));
        return;
    }

    // The menu button on small screens.
    var menuButton = clicked.closest('.nav-toggle');
    if (menuButton) {
        var menus = document.querySelectorAll('.nav, .sidebar');
        for (var i = 0; i < menus.length; i++) {
            menus[i].classList.toggle('open');
        }
        var isOpen = menus.length > 0 && menus[0].classList.contains('open');
        menuButton.setAttribute('aria-expanded', String(isOpen));
        return;
    }

    // "Weekdays" and "Everyday" shortcuts for the day checkboxes.
    var daysButton = clicked.closest('[data-days]');
    if (daysButton) {
        var form = daysButton.closest('form');
        var shortcut = daysButton.dataset.days;
        var weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
        var checkboxes = form.querySelectorAll('input[name="days[]"]');
        for (var j = 0; j < checkboxes.length; j++) {
            if (shortcut === 'all') {
                checkboxes[j].checked = true;
            } else {
                checkboxes[j].checked = weekdays.indexOf(checkboxes[j].value) !== -1;
            }
        }
        return;
    }
});

// ---------- Forms ----------
document.addEventListener('submit', function (event) {
    var form = event.target;

    // Ask before dangerous actions: data-confirm="Delete this?"
    if (form.dataset.confirm) {
        if (!confirm(form.dataset.confirm)) {
            event.preventDefault();
            return;
        }
    }

    // Stop double submissions for 3 seconds.
    if (form.dataset.busy) {
        event.preventDefault();
        return;
    }
    form.dataset.busy = '1';
    setTimeout(function () {
        delete form.dataset.busy;
    }, 3000);
});

// Selects and switches with data-autosubmit send their form when changed.
document.addEventListener('change', function (event) {
    if (event.target.hasAttribute('data-autosubmit')) {
        event.target.form.submit();
    }
});

// ---------- Values that Blade passes with data-* attributes ----------
function applyDataStyles() {
    var coloredItems = document.querySelectorAll('[data-color]');
    for (var i = 0; i < coloredItems.length; i++) {
        coloredItems[i].style.background = coloredItems[i].dataset.color;
    }
    var widthItems = document.querySelectorAll('[data-width]');
    for (var j = 0; j < widthItems.length; j++) {
        widthItems[j].style.width = widthItems[j].dataset.width + '%';
    }
}
applyDataStyles();

// ---------- Map helpers (Leaflet) ----------
var SURIGAO_CENTER = [9.7880, 125.4950];

function makeMap(elementId, options) {
    if (!options) {
        options = {};
    }
    var center = options.center;
    if (!center) {
        center = SURIGAO_CENTER;
    }
    var zoom = options.zoom;
    if (!zoom) {
        zoom = 15;
    }
    // If Leaflet did not load (offline, blocked), show a clear message and let the rest of the page work.
    if (typeof L === 'undefined') {
        showMapProblem(elementId);
        return null;
    }
    var map;
    try {
        map = L.map(elementId, { scrollWheelZoom: false }).setView(center, zoom);
    } catch (error) {
        showMapProblem(elementId);
        return null;
    }
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);
    return map;
}

// Replaces the map box with a short message. The rest of the page keeps working.
function showMapProblem(elementId) {
    var box = document.getElementById(elementId);
    if (!box || box.dataset.mapProblem) {
        return;
    }
    box.dataset.mapProblem = '1';
    box.classList.add('map-unavailable');
    box.textContent = '';
    var message = document.createElement('p');
    message.setAttribute('role', 'alert');
    message.textContent = 'The map could not be loaded. Check your internet connection and refresh the page. The rest of this page still works.';
    box.appendChild(message);
}

// Builds a popup from plain text. Text is added with textContent, so saved text can never become HTML.
function buildPopup(titleText, detailText) {
    var box = document.createElement('div');
    var title = document.createElement('b');
    title.textContent = titleText;
    box.appendChild(title);
    if (detailText) {
        box.appendChild(document.createElement('br'));
        box.appendChild(document.createTextNode(detailText));
    }
    return box;
}

function drawRoutes(map, routes) {
    var layers = [];
    for (var i = 0; i < routes.length; i++) {
        var route = routes[i];
        if (!route.path || route.path.length < 2) {
            continue;
        }
        var line = L.polyline(route.path, { color: route.color, weight: 6, opacity: 0.9 });
        line.bindPopup(buildPopup(route.truck + ' — ' + route.name, 'Barangay ' + route.barangay));
        line.addTo(map);
        line.meta = route;
        layers.push(line);
    }
    return layers;
}

function fitLayers(map, layers) {
    if (map && layers.length > 0) {
        map.fitBounds(L.featureGroup(layers).getBounds().pad(0.2));
    }
}

// Rough distance (in meters) from a point to a route.
function distToRoute(point, path) {
    var best = 1e9;
    var metersPerDegree = 111320;
    for (var i = 0; i < path.length - 1; i++) {
        var start = path[i];
        var end = path[i + 1];
        for (var step = 0; step <= 1; step += 0.1) {
            var lat = start[0] + (end[0] - start[0]) * step;
            var lng = start[1] + (end[1] - start[1]) * step;
            var dy = (lat - point[0]) * metersPerDegree;
            var dx = (lng - point[1]) * metersPerDegree * Math.cos(point[0] * Math.PI / 180);
            var distance = Math.sqrt(dx * dx + dy * dy);
            if (distance < best) {
                best = distance;
            }
        }
    }
    return best;
}
