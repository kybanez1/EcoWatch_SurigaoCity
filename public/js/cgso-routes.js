// CGSO Route Assignment: draw the route on the map and list its streets.
var pageData = readPageData();
var map = makeMap('map', { zoom: 15 }); // null when Leaflet did not load
var path = pageData.path || [];
var streets = pageData.streets || [];
var otherRoutes = pageData.routes || [];
var drawing = false;

var truckSelect = document.getElementById('truck');
var pathInput = document.getElementById('path');
var streetsInput = document.getElementById('streetsJson');
var streetList = document.getElementById('streets');
var streetInput = document.getElementById('street');
var drawButton = document.getElementById('drawBtn');
var drawHint = document.getElementById('drawHint');

// Other routes are shown faded in the background.
var fitList = [];
for (var i = 0; map && i < otherRoutes.length; i++) {
    var other = otherRoutes[i];
    if (other.id !== pageData.currentId && other.path.length > 1) {
        var otherLine = L.polyline(other.path, { color: other.color, weight: 5, opacity: 0.55 });
        otherLine.bindPopup(buildPopup(other.truck + ' — ' + other.name));
        otherLine.addTo(map);
        fitList.push(otherLine);
    }
}

var line = null;
var markers = null;
if (map) {
    line = L.polyline([], { weight: 7 }).addTo(map);
    markers = L.layerGroup().addTo(map);
}

function truckColor() {
    var selected = truckSelect.options[truckSelect.selectedIndex];
    if (selected && selected.dataset.color) {
        return selected.dataset.color;
    }
    return '#2f7d4f';
}

function addMarker(index) {
    var marker = L.marker(path[index], { draggable: true, opacity: 0.9 });
    marker.on('dragend', function (event) {
        var position = event.target.getLatLng();
        path[index] = [Number(position.lat.toFixed(6)), Number(position.lng.toFixed(6))];
        redraw();
    });
    marker.addTo(markers);
}

function redraw() {
    if (map) {
        line.setLatLngs(path);
        line.setStyle({ color: truckColor() });
        markers.clearLayers();
        for (var p = 0; p < path.length; p++) {
            addMarker(p);
        }
    }
    // The hidden field always holds the current path. [] means "cleared on purpose".
    pathInput.value = JSON.stringify(path);
}

function removeStreet(index) {
    streets.splice(index, 1);
    showStreets();
}

function showStreets() {
    streetList.textContent = '';
    for (var s = 0; s < streets.length; s++) {
        var item = document.createElement('li');
        item.textContent = (s + 1) + '. ' + streets[s];

        var removeButton = document.createElement('button');
        removeButton.type = 'button';
        removeButton.textContent = '✕';
        removeButton.dataset.index = s;
        removeButton.addEventListener('click', function (event) {
            removeStreet(Number(event.target.dataset.index));
        });

        item.appendChild(removeButton);
        streetList.appendChild(item);
    }
    streetsInput.value = JSON.stringify(streets);
}

function addStreet() {
    var name = streetInput.value.trim();
    if (name !== '') {
        streets.push(name);
        streetInput.value = '';
        showStreets();
    }
}

document.getElementById('addStreet').addEventListener('click', addStreet);
streetInput.addEventListener('keydown', function (event) {
    if (event.key === 'Enter') {
        event.preventDefault();
        addStreet();
    }
});

drawButton.addEventListener('click', function () {
    drawing = !drawing;
    if (drawing) {
        drawButton.textContent = '✔ Done Drawing';
        drawHint.textContent = 'Drawing: click the map to add points. Drag a marker to adjust it.';
    } else {
        drawButton.textContent = '✎ Draw Route';
        drawHint.textContent = 'Click "Draw Route", then click points along the streets on the map.';
    }
});

if (map) {
    map.on('click', function (event) {
        if (!drawing) {
            return;
        }
        path.push([Number(event.latlng.lat.toFixed(6)), Number(event.latlng.lng.toFixed(6))]);
        redraw();
    });
} else {
    // Without a map the route cannot be drawn. The saved path stays in the form and is kept when saving.
    drawButton.disabled = true;
    drawHint.textContent = 'The map is not available, so the route cannot be drawn now. You can still change the truck, name, barangay and streets. The saved route line is kept.';
}

document.getElementById('undo').addEventListener('click', function () {
    path.pop();
    redraw();
});

document.getElementById('clear').addEventListener('click', function () {
    path = [];
    redraw();
});

// The Delete button submits the separate delete form.
var deleteButton = document.getElementById('deleteRouteBtn');
if (deleteButton) {
    deleteButton.addEventListener('click', function () {
        document.getElementById('del-form').requestSubmit();
    });
}

truckSelect.addEventListener('change', redraw);

redraw();
showStreets();
if (map && path.length > 1) {
    fitList.push(line);
}
if (map) {
    fitLayers(map, fitList);
}
