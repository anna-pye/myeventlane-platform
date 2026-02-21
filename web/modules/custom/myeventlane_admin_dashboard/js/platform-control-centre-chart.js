/**
 * @file
 * Renders revenue line chart from drupalSettings.myeventlaneAdminDashboard.revenueSeries.
 */
(function (Drupal, drupalSettings) {
  'use strict';

  function initChart() {
    const data = drupalSettings.myeventlaneAdminDashboard?.revenueSeries;
    if (!data || !data.labels || data.labels.length === 0) {
      return;
    }

    const canvas = document.getElementById('mel-revenue-chart');
    if (!canvas) {
      return;
    }

    const ctx = canvas.getContext('2d');
    if (!ctx) {
      return;
    }

    const config = {
      type: 'line',
      data: {
        labels: data.labels,
        datasets: [
          {
            label: 'Gross',
            data: data.gross || [],
            borderColor: 'rgb(25, 135, 84)',
            backgroundColor: 'rgba(25, 135, 84, 0.1)',
            tension: 0.2,
            fill: true,
          },
          {
            label: 'Commission',
            data: data.commission || [],
            borderColor: 'rgb(108, 117, 125)',
            backgroundColor: 'rgba(108, 117, 125, 0.1)',
            tension: 0.2,
            fill: true,
          },
          {
            label: 'Net',
            data: data.net || [],
            borderColor: 'rgb(13, 110, 253)',
            backgroundColor: 'rgba(13, 110, 253, 0.1)',
            tension: 0.2,
            fill: true,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        aspectRatio: 2.5,
        plugins: {
          legend: {
            position: 'top',
          },
          tooltip: {
            callbacks: {
              label: function (item) {
                return item.dataset.label + ': $' + Number(item.raw).toFixed(2);
              },
            },
          },
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: {
              callback: function (value) {
                return '$' + value;
              },
            },
          },
          x: {
            maxTicksLimit: 15,
          },
        },
      },
    };

    if (typeof Chart !== 'undefined') {
      new Chart(ctx, config);
    }
  }

  Drupal.behaviors.myeventlaneAdminDashboardChart = {
    attach: function (context, settings) {
      const canvas = context.querySelector('#mel-revenue-chart');
      if (canvas && !canvas.dataset.initialized) {
        if (typeof Chart !== 'undefined') {
          initChart();
          canvas.dataset.initialized = 'true';
        }
      }
    },
  };
})(Drupal, drupalSettings);
