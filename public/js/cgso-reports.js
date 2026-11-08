// CGSO Reports page: map of all report locations.
var pageData = readPageData();
var map = makeMap('map', { zoom: 15 });
var markers = [];
var pins = pageData.pins || [];

for (var i = 0; map && i < pins.length; i++) {
    var pin = pins[i];

    // Marker color depends on the report status.
    var color = '#1971c2';
    if (pin.status === 'Resolved') {
        color = '#2f7d4f';
    } else if (pin.status === 'For Verification') {
        color = '#e0a030';
    } else if (pin.status === 'Reopened' || pin.status === 'Not Resolved') {
        color = '#e03131';
    }

    var marker = L.circleMarker([pin.lat, pin.lng], { radius: 9, color: '#fff', weight: 2, fillColor: color, fillOpacity: 1 });
    marker.bindPopup(buildPopup(pin.code, pin.category + ' — ' + pin.status));
    marker.addTo(map);
    markers.push(marker);
}
if (map) {
    fitLayers(map, markers);
}
