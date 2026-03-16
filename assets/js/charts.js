// ===== assets/js/charts.js =====
function initCharts() {
    // Tasks Chart
    fetch('/api/analytics.php?type=tasks')
        .then(res => res.json())
        .then(data => {
            const ctx = document.getElementById('tasksChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.tasks.labels,
                    datasets: [
                        {
                            label: 'Created',
                            data: data.tasks.created,
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            tension: 0.4
                        },
                        {
                            label: 'Completed',
                            data: data.tasks.completed,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            tension: 0.4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'bottom'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });
        });

    // Applications Status Chart
    fetch('/api/analytics.php?type=applications')
        .then(res => res.json())
        .then(data => {
            const ctx = document.getElementById('applicationsChart').getContext('2d');
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: data.applications.labels,
                    datasets: [{
                        data: data.applications.data,
                        backgroundColor: [
                            '#3b82f6',
                            '#f59e0b',
                            '#10b981',
                            '#ef4444',
                            '#8b5cf6'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        });

    // Revenue Chart
    fetch('/api/analytics.php?type=revenue')
        .then(res => res.json())
        .then(data => {
            const ctx = document.getElementById('revenueChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: data.revenue.labels,
                    datasets: [{
                        label: 'Revenue (₹)',
                        data: data.revenue.data,
                        backgroundColor: '#10b981',
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            ticks: {
                                callback: function(value) {
                                    return '₹' + value.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
        });
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', initCharts);