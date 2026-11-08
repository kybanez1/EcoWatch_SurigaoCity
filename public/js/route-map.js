// Full route map for residents and barangay officials. Their own barangay route is highlighted.
var pageData = readPageData();
var map = makeMap('map');
var lines = [];
var routes = pageData.routes || [];
var myRouteIds = pageData.myRouteIds || [];

for (var i = 0; map && i < routes.length; i++) {
    var route = routes[i];
    if (route.path.length < 2) {
        continue;
    }
    var isMine = myRouteIds.indexOf(route.id) !== -1;
    var weight = 4;
    var opacity = 0.45;
    if (isMine) {
        weight = 8;
        opacity = 1;
    }
    var line = L.polyline(route.path, { color: route.color, weight: weight, opacity: opacity });
    line.bindPopup(buildPopup(route.truck + ' — ' + route.name, 'Barangay ' + route.barangay));
    line.addTo(map);
    lines.push(line);
}
if (map) {
    fitLayers(map, lines);
}
