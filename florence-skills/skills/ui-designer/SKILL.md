# UI Designer Skill - Florence With Locals

You are a UI designer specializing in the Florence With Locals tour management system. You understand the existing design system, TailwindCSS patterns, and tourism industry UX best practices.

## Project Design Context

### Technology Stack
- **CSS Framework:** TailwindCSS 3.4
- **Component Library:** Custom components in `src/components/UI/`
- **Layout System:** `ModernLayout.jsx` with responsive sidebar
- **Icons:** react-icons (Fi* prefix from Feather Icons)

### Existing Component Files
```
src/components/
├── Layout/
│   └── ModernLayout.jsx    # Main layout with sidebar navigation
├── UI/
│   ├── Card.jsx            # Card container component
│   ├── Button.jsx          # Button with variants
│   └── Input.jsx           # Form input components
├── Dashboard.jsx           # Stats and tour lists
├── BookingDetailsModal.jsx # 6-section modal (409 lines)
├── TourCards.jsx           # Compact tour cards
└── PaymentRecordForm.jsx   # Payment entry form
```

---

## Florence/Tuscan Color Palette

### Primary Colors (Terracotta & Earth Tones)
```javascript
// tailwind.config.js extension
module.exports = {
  theme: {
    extend: {
      colors: {
        // Primary - Terracotta (Tuscan clay)
        terracotta: {
          50: '#fdf5f3',
          100: '#fbe8e4',
          200: '#f9d5cd',
          300: '#f3b5a7',
          400: '#ea8a74',
          500: '#dc6a4f',  // Primary
          600: '#c85035',
          700: '#a8412a',
          800: '#8b3927',
          900: '#743426',
        },
        // Secondary - Gold (Renaissance gold)
        gold: {
          50: '#fefdf7',
          100: '#fdf9e7',
          200: '#fbf1c7',
          300: '#f7e39d',
          400: '#f2cf65',
          500: '#e9b93e',  // Primary gold
          600: '#d49a24',
          700: '#b17a1f',
          800: '#8f6120',
          900: '#76501e',
        },
        // Accent - Olive (Tuscan olive groves)
        olive: {
          50: '#f7f8f4',
          100: '#eef0e6',
          200: '#dce1cd',
          300: '#c2cbaa',
          400: '#a5b182',
          500: '#8a9a64',  // Primary olive
          600: '#6d7c4d',
          700: '#55613e',
          800: '#464f35',
          900: '#3c432f',
        },
        // Neutral - Stone (Florentine stone)
        stone: {
          50: '#fafaf9',
          100: '#f5f5f4',
          200: '#e7e5e4',
          300: '#d6d3d1',
          400: '#a8a29e',
          500: '#78716c',
          600: '#57534e',
          700: '#44403c',
          800: '#292524',
          900: '#1c1917',
        },
        // Status Colors (keep standard for clarity)
        success: '#10b981',
        warning: '#f59e0b',
        error: '#ef4444',
        info: '#3b82f6',
      },
    },
  },
};
```

### Usage Guidelines
```jsx
// Primary actions - Terracotta
<button className="bg-terracotta-500 hover:bg-terracotta-600 text-white">
  Book Tour
</button>

// Secondary/Premium - Gold
<span className="text-gold-600 bg-gold-50 border border-gold-200">
  Premium Tour
</span>

// Nature/Availability - Olive
<div className="bg-olive-50 text-olive-700 border-olive-200">
  Available
</div>

// Neutral backgrounds - Stone
<div className="bg-stone-50 text-stone-700">
  Content area
</div>
```

---

## Typography Standards

### Font Stack
```css
/* Already in TailwindCSS defaults, but customize if needed */
font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont,
             "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
```

### Heading Hierarchy
```jsx
// Page titles
<h1 className="text-2xl font-bold text-gray-900">Dashboard</h1>

// Section titles
<h2 className="text-xl font-semibold text-gray-900">Upcoming Tours</h2>

// Card titles
<h3 className="text-lg font-medium text-gray-900">Tour Details</h3>

// Subsection titles
<h4 className="text-base font-medium text-gray-700">Customer Information</h4>

// Labels
<span className="text-sm font-medium text-gray-600">Guide Name</span>

// Body text
<p className="text-sm text-gray-600">Description text here</p>

// Small/caption text
<span className="text-xs text-gray-500">Last updated: 2 hours ago</span>
```

### Text Color Guidelines
```jsx
// Primary text (headings, important content)
className="text-gray-900"

// Secondary text (descriptions, supporting content)
className="text-gray-600"

// Muted text (timestamps, captions)
className="text-gray-500"

// Disabled text
className="text-gray-400"

// Links
className="text-blue-600 hover:text-blue-700"

// Error text
className="text-red-600"

// Success text
className="text-green-600"
```

---

## Button Component Standards

### Button Variants (matching existing Button.jsx pattern)
```jsx
// Primary Button - Main actions
<Button variant="primary" size="md" icon={FiPlus}>
  Add Tour
</Button>
// Classes: bg-blue-600 hover:bg-blue-700 text-white

// Secondary Button - Secondary actions
<Button variant="secondary" size="md">
  Cancel
</Button>
// Classes: bg-gray-100 hover:bg-gray-200 text-gray-700 border border-gray-300

// Ghost Button - Tertiary actions
<Button variant="ghost" size="sm" icon={FiEye}>
  View All
</Button>
// Classes: text-gray-600 hover:text-gray-900 hover:bg-gray-100

// Danger Button - Destructive actions
<Button variant="danger" size="md">
  Delete
</Button>
// Classes: bg-red-600 hover:bg-red-700 text-white

// Success Button - Positive actions
<Button variant="success" size="md">
  Confirm
</Button>
// Classes: bg-green-600 hover:bg-green-700 text-white

// Tuscan Primary (for booking-related actions)
<button className="bg-terracotta-500 hover:bg-terracotta-600 text-white
                   px-4 py-2 rounded-lg font-medium transition-colors">
  Book Now
</button>
```

### Button Sizes
```jsx
// Small - Inline actions, table rows
size="sm"
// Classes: px-3 py-1.5 text-sm

// Medium - Default
size="md"
// Classes: px-4 py-2 text-sm

// Large - Primary CTAs
size="lg"
// Classes: px-6 py-3 text-base
```

### Button with Icon Pattern
```jsx
import { FiPlus, FiRefreshCw, FiEye } from 'react-icons/fi';

<Button variant="primary" icon={FiPlus}>
  Add Tour
</Button>

// Icon only button
<button className="p-2 rounded-lg hover:bg-gray-100 text-gray-500
                   hover:text-gray-700 transition-colors">
  <FiRefreshCw className="w-4 h-4" />
</button>

// Loading state
<Button variant="primary" disabled>
  <FiRefreshCw className="w-4 h-4 animate-spin mr-2" />
  Syncing...
</Button>
```

---

## Card Component Standards

### Basic Card (matching existing Card.jsx)
```jsx
<Card>
  <div className="flex items-center justify-between mb-4">
    <h2 className="text-xl font-semibold text-gray-900">Card Title</h2>
    <Button variant="ghost" size="sm" icon={FiEye}>View All</Button>
  </div>
  {/* Card content */}
</Card>

// Card.jsx implementation
const Card = ({ children, className = '' }) => (
  <div className={`bg-white rounded-lg border border-gray-200 p-6 ${className}`}>
    {children}
  </div>
);
```

### Stats Card Pattern (from Dashboard.jsx)
```jsx
<div className="bg-white rounded-lg border border-gray-200 p-4
                hover:shadow-md transition-shadow">
  <div className="flex items-center justify-between">
    <div>
      <p className="text-sm text-gray-600 mb-1">Unassigned Tours</p>
      <p className="text-3xl font-bold text-gray-900">12</p>
      <p className="text-xs text-gray-500 mt-1">Future tours without guide</p>
    </div>
    <div className="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
      <FiAlertCircle className="text-orange-600 text-xl" />
    </div>
  </div>
</div>
```

### Tour Card Pattern (compact horizontal)
```jsx
<div className="flex items-center justify-between p-3 rounded-lg
                border border-gray-100 hover:bg-gray-50 transition-colors">
  <div className="flex-1">
    <h3 className="font-medium text-gray-900">{tour.title}</h3>
    <div className="flex items-center space-x-4 text-sm text-gray-600 mt-1">
      <span>{formattedDate}</span>
      <span>{tour.time}</span>
      <span>{tour.guide_name || 'Unassigned'}</span>
      {tour.language && (
        <span className="inline-flex items-center px-2 py-0.5
                         bg-blue-50 text-blue-700 text-xs font-medium rounded">
          {tour.language}
        </span>
      )}
    </div>
  </div>
  {tour.booking_channel && (
    <span className="ml-2 px-2 py-1 bg-purple-100 text-purple-700
                     text-xs font-medium rounded-full">
      {tour.booking_channel}
    </span>
  )}
</div>
```

### Clickable Card Pattern
```jsx
<div className="bg-white rounded-lg border border-gray-200 p-4
                cursor-pointer hover:border-blue-300 hover:shadow-md
                transition-all duration-200"
     onClick={handleClick}>
  {/* Card content */}
</div>
```

---

## Form Input Standards

### Text Input
```jsx
<div className="space-y-1">
  <label className="block text-sm font-medium text-gray-700">
    Customer Name
  </label>
  <input
    type="text"
    className="w-full px-3 py-2 border border-gray-300 rounded-lg
               focus:ring-2 focus:ring-blue-500 focus:border-blue-500
               placeholder:text-gray-400 text-sm"
    placeholder="Enter customer name"
  />
</div>
```

### Select Input
```jsx
<div className="space-y-1">
  <label className="block text-sm font-medium text-gray-700">
    Guide
  </label>
  <select className="w-full px-3 py-2 border border-gray-300 rounded-lg
                     focus:ring-2 focus:ring-blue-500 focus:border-blue-500
                     text-sm bg-white">
    <option value="">Select a guide</option>
    {guides.map(guide => (
      <option key={guide.id} value={guide.id}>{guide.name}</option>
    ))}
  </select>
</div>
```

### Date Picker (using react-datepicker)
```jsx
import DatePicker from 'react-datepicker';
import 'react-datepicker/dist/react-datepicker.css';

<DatePicker
  selected={selectedDate}
  onChange={setSelectedDate}
  dateFormat="dd/MM/yyyy"
  className="w-full px-3 py-2 border border-gray-300 rounded-lg
             focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
/>
```

### Error State
```jsx
<div className="space-y-1">
  <label className="block text-sm font-medium text-gray-700">Email</label>
  <input
    type="email"
    className="w-full px-3 py-2 border border-red-300 rounded-lg
               focus:ring-2 focus:ring-red-500 focus:border-red-500
               text-sm bg-red-50"
  />
  <p className="text-sm text-red-600">Please enter a valid email address</p>
</div>
```

---

## Shadow and Border Radius Standards

### Shadows
```jsx
// Subtle shadow (cards, dropdowns)
className="shadow-sm"

// Default shadow (hover states, elevated cards)
className="shadow"

// Medium shadow (modals, popovers)
className="shadow-md"

// Large shadow (dialogs, overlays)
className="shadow-lg"

// Extra large (floating elements)
className="shadow-xl"
```

### Border Radius
```jsx
// Small elements (badges, tags)
className="rounded"        // 4px

// Default (inputs, buttons)
className="rounded-lg"     // 8px

// Cards, modals
className="rounded-lg"     // 8px (consistent)

// Pills, avatars
className="rounded-full"   // 50%
```

---

## Animation and Transition Standards

### Standard Transitions
```jsx
// Color transitions (buttons, links)
className="transition-colors duration-200"

// All property transitions (cards, interactive elements)
className="transition-all duration-200"

// Shadow transitions (hover effects)
className="transition-shadow duration-200"

// Transform transitions (scale, rotate)
className="transition-transform duration-200"
```

### Hover Effects
```jsx
// Card hover
className="hover:shadow-md transition-shadow"

// Button hover
className="hover:bg-blue-700 transition-colors"

// Row hover (tables, lists)
className="hover:bg-gray-50 transition-colors"

// Scale on hover (cards, images)
className="hover:scale-105 transition-transform"
```

### Loading States
```jsx
// Spinner
<div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600" />

// Pulse (skeleton loading)
<div className="animate-pulse bg-gray-200 rounded h-4 w-32" />

// Icon spin
<FiRefreshCw className="w-4 h-4 animate-spin" />
```

---

## Status Badges

### Payment Status
```jsx
// Paid
<span className="inline-block px-2 py-1 text-xs font-medium rounded-full
                 bg-green-100 text-green-700">
  Paid
</span>

// Partial
<span className="inline-block px-2 py-1 text-xs font-medium rounded-full
                 bg-yellow-100 text-yellow-700">
  Partial
</span>

// Unpaid
<span className="inline-block px-2 py-1 text-xs font-medium rounded-full
                 bg-red-100 text-red-700">
  Unpaid
</span>
```

### Tour Status
```jsx
// Confirmed
<span className="px-2 py-1 bg-green-50 text-green-700 text-xs rounded">
  Confirmed
</span>

// Cancelled
<span className="px-2 py-1 bg-red-50 text-red-700 text-xs rounded">
  Cancelled
</span>

// Rescheduled
<span className="px-2 py-1 bg-orange-50 text-orange-700 text-xs rounded">
  Rescheduled
</span>

// Pending
<span className="px-2 py-1 bg-gray-100 text-gray-700 text-xs rounded">
  Pending
</span>
```

### Booking Channel Badge
```jsx
// Viator
<span className="px-2 py-1 bg-purple-100 text-purple-700 text-xs font-medium rounded-full">
  Viator
</span>

// GetYourGuide
<span className="px-2 py-1 bg-orange-100 text-orange-700 text-xs font-medium rounded-full">
  GetYourGuide
</span>

// Direct
<span className="px-2 py-1 bg-blue-100 text-blue-700 text-xs font-medium rounded-full">
  Direct
</span>
```

---

## Tourism/Booking Industry Best Practices

### Tour Listing Display
1. Always show date and time prominently
2. Include participant count
3. Show guide assignment status clearly
4. Display booking channel source
5. Indicate language for international tours
6. Show payment status with color coding

### Booking Flow
1. Clear step indicators for multi-step processes
2. Summary before confirmation
3. Instant feedback on actions
4. Easy modification/cancellation options

### Dashboard Design
1. Key metrics visible immediately
2. Action items (unassigned, unpaid) highlighted
3. Recent activity visible
4. Quick access to common actions

### Mobile Considerations
1. Touch-friendly button sizes (min 44px)
2. Collapsible navigation on mobile
3. Simplified forms on smaller screens
4. Bottom sheet modals instead of centered modals

---

## PDF Report Styling (NEW - Jan 2026)

PDF reports use the same Tuscan design system. Located in `src/utils/pdfGenerator.js`.

### PDF Color Palette
```javascript
// Tuscan theme colors for PDF (RGB values)
const colors = {
  terracotta: [199, 93, 58],    // #C75D3A - Primary
  darkText: [51, 51, 51],       // #333333
  lightGray: [128, 128, 128],   // #808080
  tableHeader: [250, 250, 250], // #FAFAFA
  tableBorder: [224, 224, 224], // #E0E0E0
  white: [255, 255, 255],
};
```

### PDF Report Structure
```javascript
// Standard PDF document setup
const doc = new jsPDF({
  orientation: 'landscape',  // Better for tables
  unit: 'mm',
  format: 'a4'
});

// Header with Tuscan branding
doc.setFillColor(199, 93, 58);  // Terracotta
doc.rect(0, 0, pageWidth, 25, 'F');
doc.setTextColor(255, 255, 255);
doc.setFontSize(20);
doc.text('Florence with Locals', 14, 15);
```

### AutoTable Styling
```javascript
import autoTable from 'jspdf-autotable';

autoTable(doc, {
  head: [['Column 1', 'Column 2', 'Column 3']],
  body: data,
  startY: 35,
  styles: {
    fontSize: 9,
    cellPadding: 3,
  },
  headStyles: {
    fillColor: [199, 93, 58],  // Terracotta header
    textColor: [255, 255, 255],
    fontStyle: 'bold',
  },
  alternateRowStyles: {
    fillColor: [250, 250, 250],
  },
});
```

### Available Report Types
| Report | Function | Columns |
|--------|----------|---------|
| Guide Payment Summary | `generateGuidePaymentSummaryPDF()` | Guide, Tours, Paid, Unpaid, Total |
| Pending Payments | `generatePendingPaymentsPDF()` | Guide, Tour, Date, Participants |
| Payment Transactions | `generatePaymentTransactionsPDF()` | Guide, Tour, Amount, Method, Date |
| Monthly Summary | `generateMonthlySummaryPDF()` | Month, Total, Cash, Bank Transfer |

---

## Component Creation Checklist

When creating new UI components:

- [ ] Follow existing file naming convention (PascalCase.jsx)
- [ ] Use TailwindCSS classes only (no custom CSS)
- [ ] Include hover and focus states
- [ ] Add loading states where applicable
- [ ] Support responsive breakpoints
- [ ] Use react-icons with Fi* prefix
- [ ] Match existing color palette
- [ ] Include PropTypes or TypeScript types
- [ ] Export as default
- [ ] Document with JSDoc comments

```jsx
/**
 * Example new component following project patterns
 */
import React from 'react';
import { FiIcon } from 'react-icons/fi';

const NewComponent = ({
  title,
  children,
  variant = 'default',
  onClick
}) => {
  const variants = {
    default: 'bg-white border-gray-200',
    highlighted: 'bg-blue-50 border-blue-200',
    danger: 'bg-red-50 border-red-200',
  };

  return (
    <div
      className={`rounded-lg border p-4 ${variants[variant]}
                  hover:shadow-md transition-shadow cursor-pointer`}
      onClick={onClick}
    >
      <h3 className="text-lg font-medium text-gray-900">{title}</h3>
      <div className="mt-2 text-sm text-gray-600">
        {children}
      </div>
    </div>
  );
};

export default NewComponent;
```
