<?php
session_start();
session_destroy();
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Network Bandwidth Monitor</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/luxon"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-luxon"></script>
    <style>
        body {
            font-family: system-ui, -apple-system, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        .chart-container {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            max-width: 800px;
            margin: 0 auto;
        }
        .interface-info {
            text-align: center;
            margin-bottom: 20px;
            color: #666;
        }
        .interface-form {
            text-align: center;
            margin-bottom: 20px;
        }
        .interface-form input {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-right: 8px;
        }
        .interface-form button {
            padding: 8px 16px;
            background: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .interface-form button:hover {
            background: #45a049;
        }
        .controls {
            text-align: center;
            margin: 20px 0;
            display: flex;
            justify-content: center;
            gap: 10px;
        }
        .control-button {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .play-button {
            background: #4CAF50;
            color: white;
        }
        .pause-button {
            background: #FFA000;
            color: white;
        }
        .control-button:hover {
            opacity: 0.9;
        }
        .control-button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .status-text {
            font-size: 0.9em;
            font-weight: bold;
            color: #666;
            margin: 0px auto 10px auto;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="interface-form">
        <form method="get">
            <input type="text" name="interface" placeholder="Interface name (e.g., eth0)"
                   value="<?php echo htmlspecialchars($_GET['interface'] ?? ''); ?>">
            <button type="submit">Set Interface</button>
        </form>
    </div>
    <div class="interface-info">
        Current interface: <strong id="currentInterface">Auto-detecting...</strong>
    </div>
    <div class="controls">
        <button id="playButton" class="control-button play-button" disabled>
            ► Play
        </button>
        <button id="pauseButton" class="control-button pause-button">
            ❚❚ Pause
        </button>
    </div>
    <div class="status-text" id="statusText">Monitoring active</div>
    <div class="chart-container">
        <canvas id="bandwidthChart"></canvas>
    </div>

    <script>
        // Get the interface from URL if specified
        const urlParams = new URLSearchParams(window.location.search);
        const interfaceParam = urlParams.get('interface');

        // Initialize the chart
        const ctx = document.getElementById('bandwidthChart').getContext('2d');
        const chart = new Chart(ctx, {
            type: 'line',
            data: {
                datasets: [{
                    label: 'Download (KB/s)',
                    data: [],
                    borderColor: 'rgb(75, 192, 192)',
                    tension: 0.1,
                    fill: false
                }, {
                    label: 'Upload (KB/s)',
                    data: [],
                    borderColor: 'rgb(255, 99, 132)',
                    tension: 0.1,
                    fill: false
                }]
            },
            options: {
                responsive: true,
                animation: {
                    duration: 0
                },
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                scales: {
                    x: {
                        type: 'time',
                        time: {
                            unit: 'second',
                            displayFormats: {
                                second: 'HH:mm:ss'
                            }
                        },
                        title: {
                            display: true,
                            text: 'Time'
                        }
                    },
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'KB/s'
                        }
                    }
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'Network Bandwidth Monitor'
                    },
                    tooltip: {
                        enabled: true,
                        mode: 'index',
                        intersect: false
                    }
                }
            }
        });

        // Monitoring state
        let monitoringState = {
            active: true,
            intervalId: null,
            lastData: null
        };

        // Control buttons
        const playButton = document.getElementById('playButton');
        const pauseButton = document.getElementById('pauseButton');
        const statusText = document.getElementById('statusText');

        // Function to fetch data
        async function fetchData() {
            try {
                const url = new URL('data.php', window.location.href);
                if (interfaceParam) {
                    url.searchParams.append('interface', interfaceParam);
                }

                const response = await fetch(url);
                const data = await response.json();

                if (data.status === 'error') {
                    console.error('Error:', data.error);
                    document.getElementById('currentInterface').textContent = 'Error: ' + data.error;
                    return;
                }

                // Store last data
                monitoringState.lastData = data;

                // Update interface display
                document.getElementById('currentInterface').textContent = data.label;

                // Update chart data for download
                chart.data.datasets[0].data = data.data.map(point => ({
                    x: new Date(parseInt(point[0])),
                    y: point[1]
                }));

                // Update chart data for upload
                chart.data.datasets[1].data = data.tx_data.map(point => ({
                    x: new Date(parseInt(point[0])),
                    y: point[1]
                }));

                chart.update('none');
            } catch (error) {
                console.error('Error fetching data:', error);
                document.getElementById('currentInterface').textContent = 'Error fetching data';
            }
        }

        function startMonitoring() {
            if (!monitoringState.active) {
                monitoringState.active = true;
                monitoringState.intervalId = setInterval(fetchData, 1000);
                playButton.disabled = true;
                pauseButton.disabled = false;
                statusText.textContent = 'Monitoring active';
            }
        }

        function pauseMonitoring() {
            if (monitoringState.active) {
                monitoringState.active = false;
                clearInterval(monitoringState.intervalId);
                playButton.disabled = false;
                pauseButton.disabled = true;
                statusText.textContent = 'Monitoring paused';
            }
        }


        // Event listeners for control buttons
        playButton.addEventListener('click', startMonitoring);
        pauseButton.addEventListener('click', pauseMonitoring);

        // Initial fetch and setup interval
        fetchData();
        monitoringState.intervalId = setInterval(fetchData, 1000);
    </script>
</body>
</html>
