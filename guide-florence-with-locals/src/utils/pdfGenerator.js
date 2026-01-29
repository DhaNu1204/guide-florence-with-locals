/**
 * PDF Report Generator for Florence With Locals
 * Uses jsPDF and jsPDF-AutoTable for professional PDF reports
 */

import { jsPDF } from 'jspdf';
import autoTable from 'jspdf-autotable';

// Tuscan theme colors
const COLORS = {
  terracotta: [199, 93, 58],      // #C75D3A - Primary accent
  terracottaDark: [139, 41, 66],  // #8B2942 - Dark accent
  olive: [107, 142, 35],          // #6B8E23 - Success/positive
  gold: [218, 165, 32],           // #DAA520 - Warning/highlight
  stone900: [28, 25, 23],         // #1C1917 - Text dark
  stone600: [87, 83, 78],         // #57534E - Text medium
  stone400: [168, 162, 158],      // #A8A29E - Text light
  stone100: [245, 245, 244],      // #F5F5F4 - Background light
  white: [255, 255, 255],
};

/**
 * Format currency for display
 */
const formatCurrency = (amount) => {
  return new Intl.NumberFormat('en-EU', {
    style: 'currency',
    currency: 'EUR'
  }).format(amount || 0);
};

/**
 * Format date for display
 */
const formatDate = (dateStr) => {
  if (!dateStr) return '-';
  const date = new Date(dateStr);
  return date.toLocaleDateString('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric'
  });
};

/**
 * Add company header to PDF
 */
const addHeader = (doc, title) => {
  const pageWidth = doc.internal.pageSize.getWidth();

  // Company name
  doc.setFontSize(24);
  doc.setTextColor(...COLORS.terracottaDark);
  doc.setFont('helvetica', 'bold');
  doc.text('Florence With Locals', pageWidth / 2, 20, { align: 'center' });

  // Tagline
  doc.setFontSize(10);
  doc.setTextColor(...COLORS.stone600);
  doc.setFont('helvetica', 'normal');
  doc.text('Tour Guide Management System', pageWidth / 2, 27, { align: 'center' });

  // Decorative line
  doc.setDrawColor(...COLORS.terracotta);
  doc.setLineWidth(0.5);
  doc.line(20, 32, pageWidth - 20, 32);

  // Report title
  doc.setFontSize(16);
  doc.setTextColor(...COLORS.stone900);
  doc.setFont('helvetica', 'bold');
  doc.text(title, pageWidth / 2, 42, { align: 'center' });

  return 48; // Return Y position after header
};

/**
 * Add footer with page numbers
 */
const addFooter = (doc) => {
  const pageCount = doc.internal.getNumberOfPages();
  const pageWidth = doc.internal.pageSize.getWidth();
  const pageHeight = doc.internal.pageSize.getHeight();

  for (let i = 1; i <= pageCount; i++) {
    doc.setPage(i);

    // Footer line
    doc.setDrawColor(...COLORS.stone400);
    doc.setLineWidth(0.3);
    doc.line(20, pageHeight - 15, pageWidth - 20, pageHeight - 15);

    // Page number
    doc.setFontSize(9);
    doc.setTextColor(...COLORS.stone600);
    doc.setFont('helvetica', 'normal');
    doc.text(
      `Page ${i} of ${pageCount}`,
      pageWidth / 2,
      pageHeight - 8,
      { align: 'center' }
    );

    // Generated timestamp
    doc.setFontSize(8);
    doc.text(
      `Generated: ${new Date().toLocaleString('en-GB')}`,
      20,
      pageHeight - 8
    );

    // Company name in footer
    doc.text(
      'Florence With Locals',
      pageWidth - 20,
      pageHeight - 8,
      { align: 'right' }
    );
  }
};

/**
 * Add date range info to PDF
 */
const addDateRange = (doc, startDate, endDate, yPos) => {
  doc.setFontSize(11);
  doc.setTextColor(...COLORS.stone600);
  doc.setFont('helvetica', 'normal');

  const dateText = `Report Period: ${formatDate(startDate)} - ${formatDate(endDate)}`;
  doc.text(dateText, 20, yPos);

  return yPos + 10;
};

/**
 * Add summary box to PDF
 */
const addSummaryBox = (doc, summaryData, yPos) => {
  const pageWidth = doc.internal.pageSize.getWidth();
  const boxWidth = pageWidth - 40;
  const boxHeight = 8 + (summaryData.length * 8);

  // Box background
  doc.setFillColor(...COLORS.stone100);
  doc.setDrawColor(...COLORS.terracotta);
  doc.setLineWidth(0.5);
  doc.roundedRect(20, yPos, boxWidth, boxHeight, 3, 3, 'FD');

  // Summary title
  doc.setFontSize(11);
  doc.setTextColor(...COLORS.terracottaDark);
  doc.setFont('helvetica', 'bold');
  doc.text('Summary', 25, yPos + 7);

  // Summary items
  doc.setFontSize(10);
  doc.setFont('helvetica', 'normal');
  doc.setTextColor(...COLORS.stone900);

  let itemY = yPos + 15;
  summaryData.forEach((item, index) => {
    const xPos = 25 + (index % 3) * ((boxWidth - 10) / 3);
    if (index > 0 && index % 3 === 0) {
      itemY += 8;
    }
    doc.text(`${item.label}: ${item.value}`, xPos, itemY);
  });

  return yPos + boxHeight + 10;
};

/**
 * Generate Guide Payment Summary Report
 */
export const generateGuidePaymentSummaryPDF = (guidePayments, options = {}) => {
  const {
    startDate = null,
    endDate = null,
    filename = 'guide-payment-summary'
  } = options;

  const doc = new jsPDF();

  // Add header
  let yPos = addHeader(doc, 'Guide Payment Summary Report');

  // Add date range if provided
  if (startDate && endDate) {
    yPos = addDateRange(doc, startDate, endDate, yPos);
  } else {
    yPos += 5;
  }

  // Calculate totals
  const totalGuides = guidePayments.length;
  const totalPayments = guidePayments.reduce((sum, g) => sum + (parseFloat(g.total_payments_received) || 0), 0);
  const totalPaidTours = guidePayments.reduce((sum, g) => sum + (parseInt(g.paid_tours) || 0), 0);
  const totalUnpaidTours = guidePayments.reduce((sum, g) => sum + (parseInt(g.unpaid_tours) || 0), 0);

  // Add summary box
  yPos = addSummaryBox(doc, [
    { label: 'Total Guides', value: totalGuides },
    { label: 'Total Payments', value: formatCurrency(totalPayments) },
    { label: 'Paid Tours', value: totalPaidTours },
    { label: 'Unpaid Tours', value: totalUnpaidTours },
  ], yPos);

  // Prepare table data
  const tableData = guidePayments.map(guide => [
    guide.guide_name || '-',
    guide.guide_email || '-',
    guide.total_tours || 0,
    guide.paid_tours || 0,
    guide.unpaid_tours || 0,
    formatCurrency(guide.total_payments_received),
    `${guide.payment_completion_rate || 0}%`
  ]);

  // Add table
  autoTable(doc, {
    startY: yPos,
    head: [[
      'Guide Name',
      'Email',
      'Total Tours',
      'Paid',
      'Unpaid',
      'Total Payments',
      'Completion'
    ]],
    body: tableData,
    theme: 'striped',
    headStyles: {
      fillColor: COLORS.terracotta,
      textColor: COLORS.white,
      fontStyle: 'bold',
      fontSize: 9
    },
    bodyStyles: {
      fontSize: 9,
      textColor: COLORS.stone900
    },
    alternateRowStyles: {
      fillColor: COLORS.stone100
    },
    columnStyles: {
      0: { cellWidth: 32 },
      1: { cellWidth: 40 },
      2: { cellWidth: 18, halign: 'center' },
      3: { cellWidth: 14, halign: 'center' },
      4: { cellWidth: 16, halign: 'center' },
      5: { cellWidth: 28, halign: 'right' },
      6: { cellWidth: 20, halign: 'center' }
    },
    margin: { left: 20, right: 20 },
    didDrawPage: (data) => {
      // Add header on new pages
      if (data.pageNumber > 1) {
        addHeader(doc, 'Guide Payment Summary Report');
      }
    }
  });

  // Add footer
  addFooter(doc);

  // Save the PDF
  const dateStr = new Date().toISOString().split('T')[0];
  doc.save(`${filename}-${dateStr}.pdf`);

  return doc;
};

/**
 * Generate Pending Payments Report
 */
export const generatePendingPaymentsPDF = (pendingTours, options = {}) => {
  const {
    filename = 'pending-payments'
  } = options;

  const doc = new jsPDF();

  // Add header
  let yPos = addHeader(doc, 'Pending Guide Payments Report');
  yPos += 5;

  // Add summary box
  const totalExpected = pendingTours.reduce((sum, t) => sum + (parseFloat(t.expected_amount) || 0), 0);
  const uniqueGuides = [...new Set(pendingTours.map(t => t.guide_id))].length;

  yPos = addSummaryBox(doc, [
    { label: 'Pending Tours', value: pendingTours.length },
    { label: 'Guides Affected', value: uniqueGuides },
    { label: 'Est. Total Due', value: formatCurrency(totalExpected) },
  ], yPos);

  if (pendingTours.length === 0) {
    // No pending payments message
    doc.setFontSize(14);
    doc.setTextColor(...COLORS.olive);
    doc.setFont('helvetica', 'bold');
    doc.text('All guide payments are up to date!', doc.internal.pageSize.getWidth() / 2, yPos + 20, { align: 'center' });
  } else {
    // Prepare table data
    const tableData = pendingTours.map(tour => [
      formatDate(tour.date),
      tour.title ? (tour.title.length > 35 ? tour.title.substring(0, 35) + '...' : tour.title) : '-',
      tour.guide_name || '-',
      tour.customer_name || '-',
      tour.participants || '-',
      tour.expected_amount ? formatCurrency(tour.expected_amount) : '-'
    ]);

    // Add table
    autoTable(doc, {
      startY: yPos,
      head: [[
        'Date',
        'Tour',
        'Guide',
        'Customer',
        'Pax',
        'Expected'
      ]],
      body: tableData,
      theme: 'striped',
      headStyles: {
        fillColor: COLORS.terracotta,
        textColor: COLORS.white,
        fontStyle: 'bold',
        fontSize: 9
      },
      bodyStyles: {
        fontSize: 9,
        textColor: COLORS.stone900
      },
      alternateRowStyles: {
        fillColor: COLORS.stone100
      },
      columnStyles: {
        0: { cellWidth: 25 },
        1: { cellWidth: 55 },
        2: { cellWidth: 30 },
        3: { cellWidth: 30 },
        4: { cellWidth: 15, halign: 'center' },
        5: { cellWidth: 25, halign: 'right' }
      },
      margin: { left: 20, right: 20 },
      didDrawPage: (data) => {
        if (data.pageNumber > 1) {
          addHeader(doc, 'Pending Guide Payments Report');
        }
      }
    });
  }

  // Add footer
  addFooter(doc);

  // Save the PDF
  const dateStr = new Date().toISOString().split('T')[0];
  doc.save(`${filename}-${dateStr}.pdf`);

  return doc;
};

/**
 * Generate Payment Transactions Report
 */
export const generatePaymentTransactionsPDF = (transactions, options = {}) => {
  const {
    startDate = null,
    endDate = null,
    guideName = null,
    filename = 'payment-transactions'
  } = options;

  const doc = new jsPDF();

  // Add header
  let title = 'Payment Transactions Report';
  if (guideName) {
    title = `Payment Report: ${guideName}`;
  }
  let yPos = addHeader(doc, title);

  // Add date range
  if (startDate && endDate) {
    yPos = addDateRange(doc, startDate, endDate, yPos);
  } else {
    yPos += 5;
  }

  // Calculate totals
  const totalAmount = transactions.reduce((sum, t) => sum + (parseFloat(t.amount) || 0), 0);
  const cashTotal = transactions.filter(t => t.payment_method === 'cash')
    .reduce((sum, t) => sum + (parseFloat(t.amount) || 0), 0);
  const bankTotal = transactions.filter(t => t.payment_method === 'bank_transfer')
    .reduce((sum, t) => sum + (parseFloat(t.amount) || 0), 0);

  // Add summary box
  yPos = addSummaryBox(doc, [
    { label: 'Total Transactions', value: transactions.length },
    { label: 'Total Amount', value: formatCurrency(totalAmount) },
    { label: 'Cash Payments', value: formatCurrency(cashTotal) },
    { label: 'Bank Transfers', value: formatCurrency(bankTotal) },
  ], yPos);

  if (transactions.length === 0) {
    doc.setFontSize(12);
    doc.setTextColor(...COLORS.stone600);
    doc.text('No transactions found for the selected period.', doc.internal.pageSize.getWidth() / 2, yPos + 20, { align: 'center' });
  } else {
    // Prepare table data
    const tableData = transactions.map(tx => [
      formatDate(tx.payment_date),
      tx.guide_name || '-',
      tx.tour_title ? (tx.tour_title.length > 30 ? tx.tour_title.substring(0, 30) + '...' : tx.tour_title) : '-',
      formatCurrency(tx.amount),
      (tx.payment_method || '-').replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase()),
      tx.transaction_reference || '-'
    ]);

    // Add table
    autoTable(doc, {
      startY: yPos,
      head: [[
        'Date',
        'Guide',
        'Tour',
        'Amount',
        'Method',
        'Reference'
      ]],
      body: tableData,
      theme: 'striped',
      headStyles: {
        fillColor: COLORS.terracotta,
        textColor: COLORS.white,
        fontStyle: 'bold',
        fontSize: 9
      },
      bodyStyles: {
        fontSize: 9,
        textColor: COLORS.stone900
      },
      alternateRowStyles: {
        fillColor: COLORS.stone100
      },
      columnStyles: {
        0: { cellWidth: 25 },
        1: { cellWidth: 30 },
        2: { cellWidth: 50 },
        3: { cellWidth: 25, halign: 'right' },
        4: { cellWidth: 28 },
        5: { cellWidth: 25 }
      },
      margin: { left: 20, right: 20 },
      // Add totals row
      foot: [[
        'TOTAL',
        '',
        '',
        formatCurrency(totalAmount),
        '',
        ''
      ]],
      footStyles: {
        fillColor: COLORS.terracottaDark,
        textColor: COLORS.white,
        fontStyle: 'bold',
        fontSize: 10
      },
      didDrawPage: (data) => {
        if (data.pageNumber > 1) {
          addHeader(doc, title);
        }
      }
    });
  }

  // Add footer
  addFooter(doc);

  // Save the PDF
  const dateStr = new Date().toISOString().split('T')[0];
  doc.save(`${filename}-${dateStr}.pdf`);

  return doc;
};

/**
 * Generate Monthly Summary Report
 */
export const generateMonthlySummaryPDF = (monthlyData, options = {}) => {
  const {
    year = new Date().getFullYear(),
    guideName = null,
    filename = 'monthly-summary'
  } = options;

  const doc = new jsPDF();

  // Add header
  let title = `Monthly Payment Summary ${year}`;
  if (guideName) {
    title = `Monthly Summary: ${guideName} (${year})`;
  }
  let yPos = addHeader(doc, title);
  yPos += 5;

  // Calculate totals
  const totalAmount = monthlyData.reduce((sum, m) => sum + (parseFloat(m.total_amount) || 0), 0);
  const totalTransactions = monthlyData.reduce((sum, m) => sum + (parseInt(m.payment_count) || 0), 0);

  // Add summary box
  yPos = addSummaryBox(doc, [
    { label: 'Year', value: year },
    { label: 'Total Payments', value: formatCurrency(totalAmount) },
    { label: 'Total Transactions', value: totalTransactions },
  ], yPos);

  // Prepare table data
  const tableData = monthlyData.map(month => [
    month.month_name || '-',
    month.payment_count || 0,
    month.tours_paid || 0,
    formatCurrency(month.cash_amount || 0),
    formatCurrency(month.bank_amount || 0),
    formatCurrency(month.total_amount || 0)
  ]);

  // Add table
  autoTable(doc, {
    startY: yPos,
    head: [[
      'Month',
      'Payments',
      'Tours',
      'Cash',
      'Bank Transfer',
      'Total'
    ]],
    body: tableData,
    theme: 'striped',
    headStyles: {
      fillColor: COLORS.terracotta,
      textColor: COLORS.white,
      fontStyle: 'bold',
      fontSize: 10
    },
    bodyStyles: {
      fontSize: 10,
      textColor: COLORS.stone900
    },
    alternateRowStyles: {
      fillColor: COLORS.stone100
    },
    columnStyles: {
      0: { cellWidth: 30 },
      1: { cellWidth: 25, halign: 'center' },
      2: { cellWidth: 25, halign: 'center' },
      3: { cellWidth: 30, halign: 'right' },
      4: { cellWidth: 35, halign: 'right' },
      5: { cellWidth: 30, halign: 'right' }
    },
    margin: { left: 20, right: 20 },
    foot: [[
      'TOTAL',
      totalTransactions,
      '',
      '',
      '',
      formatCurrency(totalAmount)
    ]],
    footStyles: {
      fillColor: COLORS.terracottaDark,
      textColor: COLORS.white,
      fontStyle: 'bold',
      fontSize: 10
    }
  });

  // Add footer
  addFooter(doc);

  // Save the PDF
  doc.save(`${filename}-${year}.pdf`);

  return doc;
};

export default {
  generateGuidePaymentSummaryPDF,
  generatePendingPaymentsPDF,
  generatePaymentTransactionsPDF,
  generateMonthlySummaryPDF
};
