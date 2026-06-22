import React, { useState, useEffect, useCallback } from 'react';
import { usePageTitle } from '../contexts/PageTitleContext';
import Card from '../components/UI/Card';
import Button from '../components/UI/Button';
import {
  FiFileText,
  FiDownload,
  FiCalendar,
  FiUser,
  FiRefreshCw,
  FiClipboard,
  FiAlertCircle
} from 'react-icons/fi';
import { jsPDF } from 'jspdf';
import autoTable from 'jspdf-autotable';
import { getAllGuides, getGuideTourReport } from '../services/mysqlDB';

// Tuscan PDF palette (mirrors src/utils/pdfGenerator.js)
const PDF_COLORS = {
  terracotta: [199, 93, 58],
  terracottaDark: [139, 41, 66],
  stone900: [28, 25, 23],
  stone600: [87, 83, 78],
  stone100: [245, 245, 244],
  white: [255, 255, 255]
};

// Tour category display order (matches backend classifyTourCategory)
const CATEGORY_ORDER = ['Combo', 'Uffizi', 'Pitti', 'Accademia', 'Other'];

// All-guides overview columns: always the 4 museums; include "Other"
// only when some guide actually has Other > 0 (keeps the table clean).
const overviewCategoryColumns = (guides = []) => {
  const showOther = guides.some((g) => (g.by_category?.Other || 0) > 0);
  return showOther ? CATEGORY_ORDER : CATEGORY_ORDER.filter((c) => c !== 'Other');
};

// Default to LAST month (invoices arrive at month-end) — returns 'YYYY-MM'
const getLastMonth = () => {
  const d = new Date();
  d.setDate(1);
  d.setMonth(d.getMonth() - 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
};

// 'YYYY-MM' -> 'June 2026'
const formatPeriodLabel = (period) => {
  if (!period || !/^\d{4}-\d{2}$/.test(period)) return period || '';
  const [y, m] = period.split('-');
  const d = new Date(parseInt(y, 10), parseInt(m, 10) - 1, 1);
  return d.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
};

// 'YYYY-MM-DD' -> '12 Jun 2026'
const formatDate = (dateStr) => {
  if (!dateStr) return '-';
  const d = new Date(dateStr);
  if (isNaN(d.getTime())) return dateStr;
  return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
};

// 'HH:MM:SS' -> 'HH:MM'
const formatTime = (timeStr) => {
  if (!timeStr) return '-';
  return String(timeStr).slice(0, 5);
};

const GuideReports = () => {
  const { setPageTitle } = usePageTitle();

  const [guides, setGuides] = useState([]);
  const [selectedGuideId, setSelectedGuideId] = useState(''); // '' = all guides
  const [month, setMonth] = useState(getLastMonth());
  const [useDateRange, setUseDateRange] = useState(false);
  const [rangeStart, setRangeStart] = useState('');
  const [rangeEnd, setRangeEnd] = useState('');

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [report, setReport] = useState(null); // { mode: 'all'|'single', ...data }

  useEffect(() => {
    if (setPageTitle) setPageTitle('Guide Reports');
  }, [setPageTitle]);

  // Load guides for the dropdown
  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const all = await getAllGuides();
        if (!cancelled) setGuides(Array.isArray(all) ? all : (all?.data || []));
      } catch (err) {
        console.error('Failed to load guides:', err);
      }
    })();
    return () => { cancelled = true; };
  }, []);

  const buildParams = useCallback(() => {
    const params = {};
    if (selectedGuideId) params.guideId = selectedGuideId;
    if (useDateRange && rangeStart && rangeEnd) {
      params.start = rangeStart;
      params.end = rangeEnd;
    } else {
      params.period = month;
    }
    return params;
  }, [selectedGuideId, useDateRange, rangeStart, rangeEnd, month]);

  const handleGenerate = useCallback(async () => {
    setError('');

    if (useDateRange && (!rangeStart || !rangeEnd)) {
      setError('Please choose both a start and end date for the custom range.');
      return;
    }
    if (useDateRange && rangeStart > rangeEnd) {
      setError('Start date must be on or before the end date.');
      return;
    }

    setLoading(true);
    try {
      const params = buildParams();
      const res = await getGuideTourReport(params);
      const data = res?.data || {};
      setReport({
        mode: selectedGuideId ? 'single' : 'all',
        ...data
      });
    } catch (err) {
      console.error('Failed to generate report:', err);
      setError('Failed to generate report. Please try again.');
      setReport(null);
    } finally {
      setLoading(false);
    }
  }, [buildParams, selectedGuideId, useDateRange, rangeStart, rangeEnd]);

  // Human-readable label for the active period/range
  const getRangeLabel = () => {
    if (!report) return '';
    if (report.period) return formatPeriodLabel(report.period);
    if (report.range) return `${formatDate(report.range.start)} – ${formatDate(report.range.end)}`;
    return '';
  };

  // ---- Exports -------------------------------------------------------------

  const triggerDownload = (blob, filename) => {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  };

  const fileStamp = () => new Date().toISOString().split('T')[0];

  const exportPDF = () => {
    if (!report) return;
    const doc = new jsPDF();
    const pageWidth = doc.internal.pageSize.getWidth();
    const rangeLabel = getRangeLabel();

    // Header
    doc.setFontSize(24);
    doc.setTextColor(...PDF_COLORS.terracottaDark);
    doc.setFont('helvetica', 'bold');
    doc.text('Florence With Locals', pageWidth / 2, 20, { align: 'center' });

    doc.setFontSize(10);
    doc.setTextColor(...PDF_COLORS.stone600);
    doc.setFont('helvetica', 'normal');
    doc.text('Tour Guide Management System', pageWidth / 2, 27, { align: 'center' });

    doc.setDrawColor(...PDF_COLORS.terracotta);
    doc.setLineWidth(0.5);
    doc.line(20, 32, pageWidth - 20, 32);

    const guideName = report.mode === 'single' ? (report.guide_info?.name || 'Guide') : 'All Guides';
    const title = `Guide Tour Report — ${guideName} — ${rangeLabel}`;
    doc.setFontSize(14);
    doc.setTextColor(...PDF_COLORS.stone900);
    doc.setFont('helvetica', 'bold');
    doc.text(title, pageWidth / 2, 42, { align: 'center' });

    let head;
    let body;
    let total;
    let tableStartY = 50;
    let tableColumnStyles = {};

    if (report.mode === 'single') {
      total = report.total_tours || 0;

      // Category summary line (non-zero categories only)
      const summary = report.summary_by_category || {};
      const summaryLine = CATEGORY_ORDER
        .filter((cat) => (summary[cat] || 0) > 0)
        .map((cat) => `${cat}: ${summary[cat]}`)
        .join('   ·   ');
      if (summaryLine) {
        doc.setFontSize(10);
        doc.setTextColor(...PDF_COLORS.stone600);
        doc.setFont('helvetica', 'normal');
        doc.text(summaryLine, pageWidth / 2, 49, { align: 'center' });
        tableStartY = 56;
      }

      head = [['#', 'Date', 'Time', 'Tour', 'Type']];
      body = (report.tours || []).map((t, i) => [
        i + 1,
        formatDate(t.date),
        formatTime(t.time),
        t.title || '-',
        t.category || 'Other'
      ]);
    } else {
      const guideRows = report.guides || [];
      total = guideRows.reduce((sum, g) => sum + (parseInt(g.total_tours, 10) || 0), 0);
      const cats = overviewCategoryColumns(guideRows);
      head = [['Guide', ...cats, 'Total']];
      body = guideRows.map((g) => [
        g.guide_name || '-',
        ...cats.map((c) => g.by_category?.[c] || 0),
        g.total_tours || 0
      ]);
      // Right-align every numeric column (categories + Total); bold the Total column
      cats.forEach((_, idx) => { tableColumnStyles[idx + 1] = { halign: 'right' }; });
      tableColumnStyles[cats.length + 1] = { halign: 'right', fontStyle: 'bold' };
    }

    autoTable(doc, {
      startY: tableStartY,
      head,
      body,
      theme: 'striped',
      headStyles: { fillColor: PDF_COLORS.terracotta, textColor: PDF_COLORS.white, fontStyle: 'bold', fontSize: 9 },
      bodyStyles: { fontSize: 9, textColor: PDF_COLORS.stone900 },
      alternateRowStyles: { fillColor: PDF_COLORS.stone100 },
      columnStyles: tableColumnStyles,
      margin: { left: 20, right: 20 }
    });

    const afterTableY = (doc.lastAutoTable?.finalY || 50) + 10;
    doc.setFontSize(12);
    doc.setTextColor(...PDF_COLORS.terracottaDark);
    doc.setFont('helvetica', 'bold');
    const totalLabel = report.mode === 'single' ? `Total tours: ${total}` : `Total tours (all guides): ${total}`;
    doc.text(totalLabel, 20, afterTableY);

    const safeName = guideName.replace(/[^a-z0-9]+/gi, '_').toLowerCase();
    doc.save(`guide_tour_report_${safeName}_${fileStamp()}.pdf`);
  };

  const exportExcel = () => {
    if (!report) return;
    // No xlsx lib in the project — emit an Excel-compatible CSV (UTF-8 BOM).
    const escape = (v) => {
      const s = v === null || v === undefined ? '' : String(v);
      return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
    };

    const guideName = report.mode === 'single' ? (report.guide_info?.name || 'Guide') : 'All Guides';
    const rows = [];
    rows.push(['Guide Tour Report']);
    rows.push([report.mode === 'single' ? guideName : 'All Guides', getRangeLabel()]);
    rows.push([]);

    if (report.mode === 'single') {
      // Category summary block (all five categories, in order)
      const summary = report.summary_by_category || {};
      rows.push(['Category', 'Count']);
      CATEGORY_ORDER.forEach((cat) => rows.push([cat, summary[cat] || 0]));
      rows.push([]);

      rows.push(['#', 'Date', 'Time', 'Tour', 'Type']);
      (report.tours || []).forEach((t, i) => {
        rows.push([i + 1, formatDate(t.date), formatTime(t.time), t.title || '', t.category || 'Other']);
      });
      rows.push([]);
      rows.push(['Total tours', report.total_tours || 0]);
    } else {
      const guideRows = report.guides || [];
      const cats = overviewCategoryColumns(guideRows);
      rows.push(['Guide', ...cats, 'Total']);
      guideRows.forEach((g) => rows.push([
        g.guide_name || '',
        ...cats.map((c) => g.by_category?.[c] || 0),
        g.total_tours || 0
      ]));
      rows.push([]);
      const colTotals = cats.map((c) => guideRows.reduce((s, g) => s + (g.by_category?.[c] || 0), 0));
      const grand = guideRows.reduce((s, g) => s + (parseInt(g.total_tours, 10) || 0), 0);
      rows.push(['Total', ...colTotals, grand]);
    }

    const csv = rows.map((r) => r.map(escape).join(',')).join('\r\n');
    const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
    const safeName = guideName.replace(/[^a-z0-9]+/gi, '_').toLowerCase();
    triggerDownload(blob, `guide_tour_report_${safeName}_${fileStamp()}.csv`);
  };

  // ---- Render --------------------------------------------------------------

  const hasResults = report && (
    (report.mode === 'single' && (report.tours?.length || 0) > 0) ||
    (report.mode === 'all' && (report.guides?.length || 0) > 0)
  );

  // Category columns for the all-guides overview ("Other" only if any guide has it)
  const overviewCats = report?.mode === 'all' ? overviewCategoryColumns(report.guides || []) : [];

  return (
    <div className="space-y-6">
      {/* Page header */}
      <div className="flex items-center space-x-3">
        <div className="w-12 h-12 bg-gradient-to-br from-terracotta-500 to-terracotta-700 rounded-tuscan-lg flex items-center justify-center shadow-tuscan">
          <FiClipboard className="text-white text-2xl" />
        </div>
        <div>
          <h1 className="text-2xl font-bold text-stone-800">Guide Reports</h1>
          <p className="text-sm text-stone-500">Verify each guide's tours against their monthly invoice (read-only)</p>
        </div>
      </div>

      {/* Controls */}
      <Card>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          {/* Guide dropdown */}
          <div>
            <label className="block text-sm font-medium text-stone-700 mb-1.5">
              <FiUser className="inline mr-1.5 -mt-0.5" />Guide
            </label>
            <select
              value={selectedGuideId}
              onChange={(e) => setSelectedGuideId(e.target.value)}
              className="w-full px-3 py-2.5 min-h-[44px] border border-stone-300 rounded-tuscan-lg focus:outline-none focus:ring-2 focus:ring-terracotta-500 bg-white text-stone-800"
            >
              <option value="">All guides (month overview)</option>
              {guides.map((g) => (
                <option key={g.id} value={g.id}>{g.name}</option>
              ))}
            </select>
          </div>

          {/* Month or custom range */}
          <div>
            <label className="block text-sm font-medium text-stone-700 mb-1.5">
              <FiCalendar className="inline mr-1.5 -mt-0.5" />
              {useDateRange ? 'Date range' : 'Month'}
            </label>
            {useDateRange ? (
              <div className="flex items-center space-x-2">
                <input
                  type="date"
                  value={rangeStart}
                  onChange={(e) => setRangeStart(e.target.value)}
                  className="w-full px-3 py-2.5 min-h-[44px] border border-stone-300 rounded-tuscan-lg focus:outline-none focus:ring-2 focus:ring-terracotta-500 bg-white text-stone-800"
                />
                <span className="text-stone-400">to</span>
                <input
                  type="date"
                  value={rangeEnd}
                  onChange={(e) => setRangeEnd(e.target.value)}
                  className="w-full px-3 py-2.5 min-h-[44px] border border-stone-300 rounded-tuscan-lg focus:outline-none focus:ring-2 focus:ring-terracotta-500 bg-white text-stone-800"
                />
              </div>
            ) : (
              <input
                type="month"
                value={month}
                onChange={(e) => setMonth(e.target.value)}
                className="w-full px-3 py-2.5 min-h-[44px] border border-stone-300 rounded-tuscan-lg focus:outline-none focus:ring-2 focus:ring-terracotta-500 bg-white text-stone-800"
              />
            )}
          </div>

          {/* Generate */}
          <div className="flex items-end">
            <Button
              variant="primary"
              icon={FiRefreshCw}
              loading={loading}
              onClick={handleGenerate}
              fullWidth
            >
              Generate
            </Button>
          </div>
        </div>

        {/* Custom range toggle */}
        <div className="mt-3">
          <label className="inline-flex items-center text-sm text-stone-600 cursor-pointer">
            <input
              type="checkbox"
              checked={useDateRange}
              onChange={(e) => setUseDateRange(e.target.checked)}
              className="mr-2 h-4 w-4 rounded border-stone-300 text-terracotta-600 focus:ring-terracotta-500"
            />
            Use custom date range instead of a month
          </label>
        </div>

        {error && (
          <div className="mt-3 flex items-center text-sm text-terracotta-700 bg-terracotta-50 border border-terracotta-200 rounded-tuscan-lg px-3 py-2">
            <FiAlertCircle className="mr-2 flex-shrink-0" />{error}
          </div>
        )}
      </Card>

      {/* Results */}
      {report && (
        <Card>
          {/* Results header + exports */}
          <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-4 gap-3">
            <div>
              {report.mode === 'single' ? (
                <>
                  <h2 className="text-xl font-bold text-stone-800">{report.guide_info?.name || 'Guide'}</h2>
                  <p className="text-sm text-stone-500">
                    {getRangeLabel()}
                    {report.guide_info?.email ? ` · ${report.guide_info.email}` : ''}
                  </p>
                </>
              ) : (
                <>
                  <h2 className="text-xl font-bold text-stone-800">All Guides — Tour Overview</h2>
                  <p className="text-sm text-stone-500">{getRangeLabel()}</p>
                </>
              )}
            </div>
            <div className="flex items-center space-x-2">
              <Button variant="outline" size="sm" icon={FiFileText} onClick={exportPDF} disabled={!hasResults}>
                Export PDF
              </Button>
              <Button variant="outline" size="sm" icon={FiDownload} onClick={exportExcel} disabled={!hasResults}>
                Export Excel
              </Button>
            </div>
          </div>

          {/* Single-guide big total + category breakdown */}
          {report.mode === 'single' && (
            <div className="mb-5 flex flex-wrap items-center gap-3">
              <div className="inline-flex items-baseline space-x-2 bg-terracotta-50 border border-terracotta-200 rounded-tuscan-lg px-4 py-3">
                <span className="text-4xl font-bold text-terracotta-700">{report.total_tours || 0}</span>
                <span className="text-sm font-medium text-stone-600">tours this period</span>
              </div>
              <div className="flex flex-wrap items-center gap-2">
                {CATEGORY_ORDER
                  .filter((cat) => (report.summary_by_category?.[cat] || 0) > 0)
                  .map((cat) => {
                    const count = report.summary_by_category[cat];
                    const isOther = cat === 'Other';
                    return (
                      <span
                        key={cat}
                        title={isOther ? 'Tours that did not match a known museum type' : undefined}
                        className={`inline-flex items-baseline space-x-1 rounded-tuscan-lg border px-3 py-2 ${
                          isOther
                            ? 'bg-gold-50 border-gold-300 text-gold-800'
                            : 'bg-stone-50 border-stone-200 text-stone-700'
                        }`}
                      >
                        <span className="text-lg font-bold">{count}</span>
                        <span className="text-xs font-medium">{cat}</span>
                      </span>
                    );
                  })}
              </div>
            </div>
          )}

          {/* Empty state */}
          {!hasResults ? (
            <div className="text-center py-10 text-stone-500">
              <FiCalendar className="mx-auto text-3xl mb-2 text-stone-400" />
              <p className="font-medium">No tours found for this {report.period ? 'month' : 'range'}.</p>
              <p className="text-sm">Try a different guide or period.</p>
            </div>
          ) : report.mode === 'single' ? (
            // Single guide: # | Date | Time | Tour
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="bg-stone-100 text-stone-600 text-left">
                    <th className="px-3 py-2 font-semibold w-12">#</th>
                    <th className="px-3 py-2 font-semibold">Date</th>
                    <th className="px-3 py-2 font-semibold">Time</th>
                    <th className="px-3 py-2 font-semibold">Tour</th>
                    <th className="px-3 py-2 font-semibold">Type</th>
                  </tr>
                </thead>
                <tbody>
                  {report.tours.map((t, i) => (
                    <tr key={`${t.group_id || 't'}-${i}`} className="border-b border-stone-100 hover:bg-stone-50">
                      <td className="px-3 py-2 text-stone-500">{i + 1}</td>
                      <td className="px-3 py-2 text-stone-800 whitespace-nowrap">{formatDate(t.date)}</td>
                      <td className="px-3 py-2 text-stone-800 whitespace-nowrap">{formatTime(t.time)}</td>
                      <td className="px-3 py-2 text-stone-800">{t.title || '-'}</td>
                      <td className="px-3 py-2 whitespace-nowrap">
                        <span className={`inline-block px-2 py-0.5 rounded-full text-xs font-medium ${
                          t.category === 'Other'
                            ? 'bg-gold-100 text-gold-800'
                            : 'bg-stone-100 text-stone-700'
                        }`}>
                          {t.category || 'Other'}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            // All guides: Guide | <categories> | Total
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="bg-stone-100 text-stone-600 text-left">
                    <th className="px-3 py-2 font-semibold">Guide</th>
                    {overviewCats.map((cat) => (
                      <th key={cat} className="px-3 py-2 font-semibold text-right">{cat}</th>
                    ))}
                    <th className="px-3 py-2 font-semibold text-right">Total</th>
                  </tr>
                </thead>
                <tbody>
                  {report.guides.map((g) => (
                    <tr key={g.guide_id} className="border-b border-stone-100 hover:bg-stone-50">
                      <td className="px-3 py-2 text-stone-800">{g.guide_name}</td>
                      {overviewCats.map((cat) => {
                        const val = g.by_category?.[cat] || 0;
                        const isOther = cat === 'Other';
                        return (
                          <td
                            key={cat}
                            className={`px-3 py-2 text-right ${
                              isOther && val > 0 ? 'text-gold-800 font-medium' : 'text-stone-700'
                            }`}
                          >
                            {val || '—'}
                          </td>
                        );
                      })}
                      <td className="px-3 py-2 text-right font-semibold text-stone-900">{g.total_tours}</td>
                    </tr>
                  ))}
                </tbody>
                <tfoot>
                  <tr className="bg-stone-50 font-semibold text-stone-800">
                    <td className="px-3 py-2">Total</td>
                    {overviewCats.map((cat) => (
                      <td key={cat} className="px-3 py-2 text-right">
                        {report.guides.reduce((s, g) => s + (g.by_category?.[cat] || 0), 0)}
                      </td>
                    ))}
                    <td className="px-3 py-2 text-right">
                      {report.guides.reduce((s, g) => s + (parseInt(g.total_tours, 10) || 0), 0)}
                    </td>
                  </tr>
                </tfoot>
              </table>
            </div>
          )}
        </Card>
      )}
    </div>
  );
};

export default GuideReports;
