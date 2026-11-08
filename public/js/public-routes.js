// Public "Truck & Routes" page.
var pageData = readPageData();
var map = makeMap('map', { zoom: 15 }); // null when Leaflet did not load: the schedule lookup below still works
var routes = pageData.routes || [];
var scheduleLines = pageData.scheduleLines || {};
var layers = [];
if (map) {
    layers = drawRoutes(map, routes);
    fitLayers(map, layers);
}
var pin = null;

// Show only the routes of the chosen truck.
document.getElementById('filter').addEventListener('change', function (event) {
    if (!map) {
        return;
    }
    var truckId = event.target.value;
    var visibleLayers = [];
    for (var i = 0; i < layers.length; i++) {
        if (truckId === '' || String(layers[i].meta.truck_id) === truckId) {
            layers[i].addTo(map);
            visibleLayers.push(layers[i]);
        } else {
            map.removeLayer(layers[i]);
        }
    }
    fitLayers(map, visibleLayers);
});

// Show the schedule lines of the chosen barangay.
document.getElementById('brgy').addEventListener('change', function (event) {
    var barangayId = event.target.value;
    var infoBox = document.getElementById('brgy-info');
    infoBox.textContent = '';
    if (barangayId === '') {
        return;
    }

    var lines = scheduleLines[barangayId];
    if (lines && lines.length > 0) {
        var list = document.createElement('ul');
        list.className = 'plain-list';
        for (var i = 0; i < lines.length; i++) {
            var item = document.createElement('li');
            item.textContent = lines[i];
            list.appendChild(item);
        }
        var title = document.createElement('b');
        title.textContent = 'Schedule';
        infoBox.appendChild(title);
        infoBox.appendChild(list);
    } else {
        infoBox.textContent = 'No active schedule for this barangay yet.';
    }

    var barangayLayers = [];
    for (var j = 0; j < layers.length; j++) {
        if (String(layers[j].meta.barangay_id) === barangayId) {
            barangayLayers.push(layers[j]);
        }
    }
    if (map) {
        fitLayers(map, barangayLayers);
    }
});

// Check which route covers the clicked spot.
function checkSpot(lat, lng) {
    if (map && pin) {
        map.removeLayer(pin);
    }
    if (map) {
        pin = L.marker([lat, lng]).addTo(map);
    }

    var bestRoute = null;
    var bestDistance = 1e9;
    for (var i = 0; i < routes.length; i++) {
        var route = routes[i];
        if (route.path.length > 1) {
            var distance = distToRoute([lat, lng], route.path);
            if (distance < bestDistance) {
                bestDistance = distance;
                bestRoute = route;
            }
        }
    }

    // Text is added with textContent / text nodes, never as HTML.
    var spotBox = document.getElementById('spot');
    spotBox.textContent = '';
    if (bestRoute && bestDistance < 250) {
        var routeName = document.createElement('b');
        routeName.textContent = bestRoute.truck + ' — ' + bestRoute.name;
        spotBox.appendChild(document.createTextNode('Covered by '));
        spotBox.appendChild(routeName);
        spotBox.appendChild(document.createTextNode(' (Barangay ' + bestRoute.barangay + ', about ' + Math.round(bestDistance) + ' m from the route).'));
    } else {
        spotBox.textContent = 'No route passes within 250 m of this spot.';
    }
}

if (map) {
    map.on('click', function (event) {
        checkSpot(event.latlng.lat, event.latlng.lng);
    });
}

document.getElementById('loc').addEventListener('click', function () {
    if (!navigator.geolocation) {
        alert('Geolocation not supported.');
        return;
    }
    navigator.geolocation.getCurrentPosition(function (position) {
        if (map) {
            map.setView([position.coords.latitude, position.coords.longitude], 16);
        }
        checkSpot(position.coords.latitude, position.coords.longitude);
    }, function () {
        alert('Location permission denied.');
    });
});
