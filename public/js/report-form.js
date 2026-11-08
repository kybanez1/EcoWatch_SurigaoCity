// "Report a Problem" page: click the map to drop the pin.
var pageData = readPageData();
var map = makeMap('map', { zoom: 15 }); // null when Leaflet did not load
var marker = null;
var latInput = document.getElementById('lat');
var lngInput = document.getElementById('lng');
var submitButton = document.getElementById('submit');
var hintText = document.getElementById('hint');

function setPin(lat, lng) {
    if (!map) {
        // No map: the location still comes from "Use My Current Location".
    } else if (marker === null) {
        marker = L.marker([lat, lng], { draggable: true }).addTo(map);
        marker.on('dragend', function () {
            var position = marker.getLatLng();
            setPin(position.lat, position.lng);
        });
    } else {
        marker.setLatLng([lat, lng]);
    }
    latInput.value = lat.toFixed(7);
    lngInput.value = lng.toFixed(7);
    submitButton.disabled = false;
    hintText.textContent = 'Location selected.';
}

if (map) {
    map.on('click', function (event) {
        setPin(event.latlng.lat, event.latlng.lng);
    });
} else {
    hintText.textContent = 'The map is not available. Use "Use My Current Location" to set the pin, or refresh the page to try the map again.';
}

// Put the pin back after a validation error.
if (pageData.lat && pageData.lng) {
    setPin(Number(pageData.lat), Number(pageData.lng));
    if (map) {
        map.setView([Number(pageData.lat), Number(pageData.lng)], 16);
    }
}

document.getElementById('loc').addEventListener('click', function () {
    if (!navigator.geolocation) {
        alert('Geolocation not supported.');
        return;
    }
    navigator.geolocation.getCurrentPosition(function (position) {
        if (map) {
            map.setView([position.coords.latitude, position.coords.longitude], 17);
        }
        setPin(position.coords.latitude, position.coords.longitude);
    }, function () {
        alert('Location permission denied.');
    });
});
