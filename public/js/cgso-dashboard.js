// CGSO dashboard charts (Chart.js).
var pageData = readPageData();

// Shows a message in a chart box when the chart cannot be drawn. The rest of the dashboard keeps working.
function showChartProblem(canvasId) {
    var canvas = document.getElementById(canvasId);
    if (!canvas || !canvas.parentNode) {
        return;
    }
    var message = document.createElement('p');
    message.className = 'sub';
    message.setAttribute('role', 'alert');
    message.textContent = 'The chart could not be loaded. Check your internet connection and refresh the page. The numbers above are still correct.';
    canvas.parentNode.replaceChild(message, canvas);
}

function makeChart(canvasId, config) {
    if (typeof Chart === 'undefined') {
        showChartProblem(canvasId);
        return;
    }
    try {
        new Chart(document.getElementById(canvasId), config);
    } catch (error) {
        showChartProblem(canvasId);
    }
}

var byCategory = pageData.byCategory || {};
var byBarangay = pageData.byBarangay || {};

makeChart('chartCategory', {
    type: 'doughnut',
    data: {
        labels: Object.keys(byCategory),
        datasets: [{
            data: Object.values(byCategory),
            backgroundColor: ['#2f7d4f', '#1e9e8a', '#e0a030', '#1971c2', '#9c36b5']
        }]
    },
    options: {
        maintainAspectRatio: false,
        plugins: { legend: { position: 'top', labels: { boxWidth: 12, font: { size: 10 } } } }
    }
});

makeChart('chartBarangay', {
    type: 'bar',
    data: {
        labels: Object.keys(byBarangay),
        datasets: [{ label: 'Reports', data: Object.values(byBarangay), backgroundColor: '#2f7d4f' }]
    },
    options: {
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
    }
});
