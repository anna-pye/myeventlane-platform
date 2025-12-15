/**
 * @file
 * JavaScript for MyEventLane Analytics.
 */

(function (Drupal, drupalSettings) {
  'use strict';

  /**
   * Initialises analytics dashboard.
   */
  Drupal.behaviors.myeventlaneAnalytics = {
    attach: function (context, settings) {
      // Wait for Chart.js to load.
      if (typeof Chart === 'undefined') {
        console.warn('Chart.js not loaded');
        return;
      }

      // Initialize time series chart if data is available.
      if (settings.analytics && settings.analytics.timeSeries) {
        this.initTimeSeriesChart(context, settings.analytics.timeSeries);
      }

      // Initialize ticket breakdown chart if data is available.
      if (settings.analytics && settings.analytics.ticketBreakdown) {
        this.initTicketBreakdownChart(context, settings.analytics.ticketBreakdown);
      }

      // Initialize conversion funnel chart if data is available.
      if (settings.analytics && settings.analytics.conversionFunnel) {
        this.initConversionFunnelChart(context, settings.analytics.conversionFunnel);
      }
    },

    /**
     * Initializes time series sales chart.
     */
    initTimeSeriesChart: function (context, timeSeriesData) {
      const canvas = context.querySelector('#analytics-time-series-chart');
      if (!canvas) {
        return;
      }

      const ctx = canvas.getContext('2d');
      const labels = timeSeriesData.map(point => point.date);
      const revenueData = timeSeriesData.map(point => point.revenue);
      const ticketData = timeSeriesData.map(point => point.ticket_count);

      new Chart(ctx, {
        type: 'line',
        data: {
          labels: labels,
          datasets: [
            {
              label: 'Revenue',
              data: revenueData,
              borderColor: '#6366f1',
              backgroundColor: 'rgba(99, 102, 241, 0.1)',
              yAxisID: 'y',
              fill: true,
              tension: 0.4,
            },
            {
              label: 'Tickets Sold',
              data: ticketData,
              borderColor: '#10b981',
              backgroundColor: 'rgba(16, 185, 129, 0.1)',
              yAxisID: 'y1',
              fill: true,
              tension: 0.4,
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: {
            mode: 'index',
            intersect: false,
          },
          plugins: {
            legend: {
              display: true,
              position: 'top',
            },
            tooltip: {
              enabled: true,
            },
          },
          scales: {
            y: {
              type: 'linear',
              display: true,
              position: 'left',
              title: {
                display: true,
                text: 'Revenue ($)',
              },
            },
            y1: {
              type: 'linear',
              display: true,
              position: 'right',
              title: {
                display: true,
                text: 'Tickets',
              },
              grid: {
                drawOnChartArea: false,
              },
            },
          },
        },
      });
    },

    /**
     * Initializes ticket breakdown chart.
     */
    initTicketBreakdownChart: function (context, ticketBreakdown) {
      const canvas = context.querySelector('#analytics-ticket-breakdown-chart');
      if (!canvas) {
        return;
      }

      const ctx = canvas.getContext('2d');
      const labels = ticketBreakdown.map(ticket => ticket.ticket_type);
      const revenueData = ticketBreakdown.map(ticket => ticket.revenue);

      new Chart(ctx, {
        type: 'doughnut',
        data: {
          labels: labels,
          datasets: [
            {
              data: revenueData,
              backgroundColor: [
                '#6366f1',
                '#10b981',
                '#f59e0b',
                '#ef4444',
                '#8b5cf6',
                '#ec4899',
              ],
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: true,
              position: 'right',
            },
            tooltip: {
              callbacks: {
                label: function (context) {
                  const label = context.label || '';
                  const value = context.parsed || 0;
                  return label + ': $' + value.toFixed(2);
                },
              },
            },
          },
        },
      });
    },

    /**
     * Initializes conversion funnel chart.
     */
    initConversionFunnelChart: function (context, funnelData) {
      const canvas = context.querySelector('#analytics-conversion-funnel-chart');
      if (!canvas) {
        return;
      }

      const ctx = canvas.getContext('2d');
      const stages = ['Event Views', 'Added to Cart', 'Checkout Started', 'Completed'];
      const values = [
        funnelData.views || 0,
        funnelData.cart_additions || 0,
        funnelData.checkout_started || 0,
        funnelData.completed || 0,
      ];

      new Chart(ctx, {
        type: 'bar',
        data: {
          labels: stages,
          datasets: [
            {
              label: 'Users',
              data: values,
              backgroundColor: [
                'rgba(99, 102, 241, 0.8)',
                'rgba(16, 185, 129, 0.8)',
                'rgba(245, 158, 11, 0.8)',
                'rgba(34, 197, 94, 0.8)',
              ],
              borderColor: [
                '#6366f1',
                '#10b981',
                '#f59e0b',
                '#22c55e',
              ],
              borderWidth: 2,
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: false,
            },
            tooltip: {
              enabled: true,
            },
          },
          scales: {
            y: {
              beginAtZero: true,
              title: {
                display: true,
                text: 'Number of Users',
              },
            },
          },
        },
      });
    },
  };

})(Drupal, drupalSettings);






