# Mobile Optimizer Skill - Florence With Locals

You are a mobile optimization specialist for the Florence With Locals tour management system. You ensure all components are fully responsive and optimized for touch interactions on mobile devices.

## Project Context

### Current State
The project uses TailwindCSS 3.4 with responsive utilities, but some components need mobile optimization. Key files to reference:

```
src/components/Layout/ModernLayout.jsx   # Responsive sidebar
src/components/BookingDetailsModal.jsx   # Large modal (needs mobile optimization)
src/pages/Tours.jsx                       # Tour listing page
src/pages/Payments.jsx                    # Payment tracking
src/components/TourCards.jsx              # Tour card display
```

### Technology Stack
- TailwindCSS 3.4 (responsive utilities)
- React 18 (with hooks)
- No mobile-specific library (pure CSS/Tailwind)

---

## TailwindCSS Responsive Breakpoints

### Breakpoint Reference
```
sm:  640px   // Mobile landscape, small tablets
md:  768px   // Tablets portrait
lg:  1024px  // Tablets landscape, small laptops
xl:  1280px  // Desktop
2xl: 1536px  // Large desktop
```

### Mobile-First Approach
Always write base styles for mobile, then add larger screen overrides:

```jsx
// CORRECT: Mobile-first
<div className="px-4 md:px-6 lg:px-8">
  <h1 className="text-xl md:text-2xl lg:text-3xl">Title</h1>
</div>

// INCORRECT: Desktop-first (avoid)
<div className="px-8 sm:px-4">
  ...
</div>
```

---

## Touch Target Standards

### Minimum Touch Target: 44x44px

```jsx
// Button minimum size
<button className="min-h-[44px] min-w-[44px] px-4 py-2">
  Click Me
</button>

// Icon button with proper touch target
<button className="p-3 rounded-lg hover:bg-gray-100 touch-manipulation">
  <FiMenu className="w-5 h-5" />
</button>

// List item touch target
<div className="py-3 px-4 min-h-[48px] flex items-center">
  <span>Tour Item</span>
</div>
```

### Touch Action Utilities
```jsx
// Prevent scroll interference on interactive elements
className="touch-manipulation"

// Prevent double-tap zoom on buttons
className="touch-none"
```

---

## Mobile Navigation Pattern

### Existing Pattern (ModernLayout.jsx)
The current sidebar collapses to hamburger menu on mobile. Ensure:

```jsx
// Mobile navigation structure
const ModernLayout = ({ children }) => {
  const [sidebarOpen, setSidebarOpen] = useState(false);

  return (
    <div className="min-h-screen bg-gray-50">
      {/* Mobile Header - Only visible on mobile */}
      <div className="lg:hidden fixed top-0 left-0 right-0 z-50
                      bg-white border-b border-gray-200 h-14">
        <div className="flex items-center justify-between px-4 h-full">
          <button
            onClick={() => setSidebarOpen(true)}
            className="p-2 rounded-lg hover:bg-gray-100 touch-manipulation"
          >
            <FiMenu className="w-6 h-6" />
          </button>
          <span className="font-semibold text-gray-900">Florence Tours</span>
          <button className="p-2 rounded-lg hover:bg-gray-100">
            <FiUser className="w-6 h-6" />
          </button>
        </div>
      </div>

      {/* Mobile Sidebar Overlay */}
      {sidebarOpen && (
        <div
          className="lg:hidden fixed inset-0 z-50 bg-black/50"
          onClick={() => setSidebarOpen(false)}
        />
      )}

      {/* Sidebar - Slides in on mobile */}
      <aside className={`
        fixed top-0 left-0 z-50 h-full w-64 bg-white border-r
        transform transition-transform duration-300 ease-in-out
        ${sidebarOpen ? 'translate-x-0' : '-translate-x-full'}
        lg:translate-x-0 lg:static
      `}>
        {/* Sidebar content */}
      </aside>

      {/* Main Content */}
      <main className="lg:ml-64 pt-14 lg:pt-0">
        <div className="p-4 lg:p-6">
          {children}
        </div>
      </main>
    </div>
  );
};
```

### Bottom Navigation Alternative (for frequent actions)
```jsx
// Fixed bottom nav for mobile (alternative pattern)
<nav className="lg:hidden fixed bottom-0 left-0 right-0 z-50
                bg-white border-t border-gray-200 safe-area-inset-bottom">
  <div className="flex items-center justify-around h-16">
    <NavLink to="/" className="flex flex-col items-center py-2 px-4">
      <FiHome className="w-5 h-5" />
      <span className="text-xs mt-1">Home</span>
    </NavLink>
    <NavLink to="/tours" className="flex flex-col items-center py-2 px-4">
      <FiCalendar className="w-5 h-5" />
      <span className="text-xs mt-1">Tours</span>
    </NavLink>
    <NavLink to="/guides" className="flex flex-col items-center py-2 px-4">
      <FiUsers className="w-5 h-5" />
      <span className="text-xs mt-1">Guides</span>
    </NavLink>
    <NavLink to="/payments" className="flex flex-col items-center py-2 px-4">
      <FiDollarSign className="w-5 h-5" />
      <span className="text-xs mt-1">Payments</span>
    </NavLink>
  </div>
</nav>
```

---

## Bottom Sheet Pattern for Mobile Modals

### Replace Centered Modal with Bottom Sheet on Mobile

```jsx
// BottomSheet component for mobile-friendly modals
const BottomSheet = ({ isOpen, onClose, title, children }) => {
  if (!isOpen) return null;

  return (
    <>
      {/* Backdrop */}
      <div
        className="fixed inset-0 z-40 bg-black/50 transition-opacity"
        onClick={onClose}
      />

      {/* Bottom Sheet Container */}
      <div className={`
        fixed z-50 bg-white rounded-t-2xl shadow-xl
        transition-transform duration-300 ease-out

        /* Mobile: Full-width bottom sheet */
        inset-x-0 bottom-0 max-h-[90vh]

        /* Desktop: Centered modal */
        md:inset-auto md:top-1/2 md:left-1/2
        md:-translate-x-1/2 md:-translate-y-1/2
        md:w-full md:max-w-lg md:rounded-lg md:max-h-[85vh]
      `}>
        {/* Handle bar (mobile only) */}
        <div className="md:hidden flex justify-center py-2">
          <div className="w-12 h-1.5 bg-gray-300 rounded-full" />
        </div>

        {/* Header */}
        <div className="flex items-center justify-between px-4 py-3 border-b">
          <h2 className="text-lg font-semibold text-gray-900">{title}</h2>
          <button
            onClick={onClose}
            className="p-2 rounded-lg hover:bg-gray-100 touch-manipulation"
          >
            <FiX className="w-5 h-5" />
          </button>
        </div>

        {/* Content - Scrollable */}
        <div className="overflow-y-auto overscroll-contain p-4"
             style={{ maxHeight: 'calc(90vh - 120px)' }}>
          {children}
        </div>

        {/* Footer Actions (sticky on mobile) */}
        <div className="sticky bottom-0 p-4 bg-white border-t
                        safe-area-inset-bottom">
          <div className="flex gap-3">
            <button
              onClick={onClose}
              className="flex-1 py-3 px-4 border border-gray-300 rounded-lg
                         font-medium text-gray-700 hover:bg-gray-50"
            >
              Cancel
            </button>
            <button
              className="flex-1 py-3 px-4 bg-blue-600 text-white rounded-lg
                         font-medium hover:bg-blue-700"
            >
              Confirm
            </button>
          </div>
        </div>
      </div>
    </>
  );
};
```

### Converting BookingDetailsModal.jsx to Bottom Sheet

```jsx
// In BookingDetailsModal.jsx, update the modal container:
<div className={`
  fixed z-50 bg-white shadow-xl overflow-hidden

  /* Mobile: Bottom sheet */
  inset-x-0 bottom-0 rounded-t-2xl max-h-[90vh]

  /* Tablet and up: Centered modal */
  md:inset-auto md:top-1/2 md:left-1/2
  md:-translate-x-1/2 md:-translate-y-1/2
  md:w-full md:max-w-2xl md:rounded-lg md:max-h-[85vh]
`}>
  {/* Mobile handle */}
  <div className="md:hidden flex justify-center py-2">
    <div className="w-12 h-1.5 bg-gray-300 rounded-full" />
  </div>

  {/* Scrollable content */}
  <div className="overflow-y-auto overscroll-contain">
    {/* 6 sections content */}
  </div>
</div>
```

---

## Swipe Gestures

### Swipe to Dismiss Pattern (for bottom sheets)
```jsx
import { useState, useRef } from 'react';

const SwipeableBottomSheet = ({ isOpen, onClose, children }) => {
  const [touchStart, setTouchStart] = useState(null);
  const [touchY, setTouchY] = useState(0);
  const sheetRef = useRef(null);

  const handleTouchStart = (e) => {
    setTouchStart(e.touches[0].clientY);
  };

  const handleTouchMove = (e) => {
    if (!touchStart) return;
    const currentY = e.touches[0].clientY;
    const diff = currentY - touchStart;

    // Only allow downward swipe
    if (diff > 0) {
      setTouchY(diff);
      if (sheetRef.current) {
        sheetRef.current.style.transform = `translateY(${diff}px)`;
      }
    }
  };

  const handleTouchEnd = () => {
    // If swiped more than 100px, close
    if (touchY > 100) {
      onClose();
    } else {
      // Reset position
      if (sheetRef.current) {
        sheetRef.current.style.transform = 'translateY(0)';
      }
    }
    setTouchStart(null);
    setTouchY(0);
  };

  return (
    <div
      ref={sheetRef}
      onTouchStart={handleTouchStart}
      onTouchMove={handleTouchMove}
      onTouchEnd={handleTouchEnd}
      className="fixed inset-x-0 bottom-0 bg-white rounded-t-2xl
                 transition-transform duration-200"
    >
      {/* Swipe indicator */}
      <div className="flex justify-center py-3">
        <div className="w-12 h-1.5 bg-gray-300 rounded-full" />
      </div>
      {children}
    </div>
  );
};
```

### Swipe Actions on List Items (Delete/Edit)
```jsx
const SwipeableTourCard = ({ tour, onEdit, onDelete }) => {
  const [translateX, setTranslateX] = useState(0);
  const [startX, setStartX] = useState(0);

  const handleTouchStart = (e) => {
    setStartX(e.touches[0].clientX);
  };

  const handleTouchMove = (e) => {
    const diff = e.touches[0].clientX - startX;
    // Limit swipe distance
    const clampedDiff = Math.max(-100, Math.min(0, diff));
    setTranslateX(clampedDiff);
  };

  const handleTouchEnd = () => {
    if (translateX < -50) {
      // Show action buttons
      setTranslateX(-100);
    } else {
      setTranslateX(0);
    }
  };

  return (
    <div className="relative overflow-hidden">
      {/* Action buttons (revealed on swipe) */}
      <div className="absolute right-0 top-0 bottom-0 flex">
        <button
          onClick={onEdit}
          className="w-16 bg-blue-500 text-white flex items-center justify-center"
        >
          <FiEdit className="w-5 h-5" />
        </button>
        <button
          onClick={onDelete}
          className="w-16 bg-red-500 text-white flex items-center justify-center"
        >
          <FiTrash className="w-5 h-5" />
        </button>
      </div>

      {/* Card content */}
      <div
        style={{ transform: `translateX(${translateX}px)` }}
        onTouchStart={handleTouchStart}
        onTouchMove={handleTouchMove}
        onTouchEnd={handleTouchEnd}
        className="bg-white relative transition-transform"
      >
        {/* Tour card content */}
      </div>
    </div>
  );
};
```

---

## Mobile-Specific Booking Flow Optimizations

### Tour Listing (Tours.jsx)
```jsx
// Mobile-optimized tour list
<div className="space-y-3 md:space-y-4">
  {tours.map(tour => (
    <div
      key={tour.id}
      className="bg-white rounded-lg border border-gray-200 p-3 md:p-4
                 active:bg-gray-50 touch-manipulation"
      onClick={() => openTourDetails(tour)}
    >
      {/* Compact mobile layout */}
      <div className="flex justify-between items-start">
        <div className="flex-1 min-w-0">
          <h3 className="font-medium text-gray-900 truncate">
            {tour.title}
          </h3>
          <div className="flex flex-wrap gap-x-3 gap-y-1 mt-1 text-sm text-gray-600">
            <span className="flex items-center gap-1">
              <FiCalendar className="w-3.5 h-3.5" />
              {formatDate(tour.date)}
            </span>
            <span className="flex items-center gap-1">
              <FiClock className="w-3.5 h-3.5" />
              {tour.time}
            </span>
          </div>
        </div>
        <div className="flex flex-col items-end gap-1 ml-2">
          <StatusBadge status={tour.payment_status} />
          {tour.booking_channel && (
            <span className="text-xs text-gray-500">
              {tour.booking_channel}
            </span>
          )}
        </div>
      </div>

      {/* Guide and participants row */}
      <div className="flex justify-between items-center mt-2 pt-2 border-t border-gray-100">
        <span className="text-sm text-gray-600">
          {tour.guide_name || (
            <span className="text-orange-600">Unassigned</span>
          )}
        </span>
        <span className="text-sm text-gray-500">
          {tour.participants} {tour.participants === 1 ? 'person' : 'people'}
        </span>
      </div>
    </div>
  ))}
</div>
```

### Form Optimization for Mobile
```jsx
// Mobile-optimized form layout
<form className="space-y-4">
  {/* Full-width inputs on mobile */}
  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div>
      <label className="block text-sm font-medium text-gray-700 mb-1">
        Customer Name
      </label>
      <input
        type="text"
        className="w-full px-3 py-3 md:py-2 border border-gray-300 rounded-lg
                   text-base md:text-sm focus:ring-2 focus:ring-blue-500"
        // Use text-base on mobile to prevent iOS zoom
      />
    </div>
    <div>
      <label className="block text-sm font-medium text-gray-700 mb-1">
        Email
      </label>
      <input
        type="email"
        inputMode="email"
        autoComplete="email"
        className="w-full px-3 py-3 md:py-2 border border-gray-300 rounded-lg
                   text-base md:text-sm"
      />
    </div>
  </div>

  {/* Number input with proper keyboard */}
  <div>
    <label className="block text-sm font-medium text-gray-700 mb-1">
      Participants
    </label>
    <input
      type="number"
      inputMode="numeric"
      pattern="[0-9]*"
      className="w-full px-3 py-3 md:py-2 border border-gray-300 rounded-lg
                 text-base md:text-sm"
    />
  </div>

  {/* Date input */}
  <div>
    <label className="block text-sm font-medium text-gray-700 mb-1">
      Tour Date
    </label>
    <input
      type="date"
      className="w-full px-3 py-3 md:py-2 border border-gray-300 rounded-lg
                 text-base md:text-sm"
    />
  </div>

  {/* Submit button - Full width on mobile */}
  <button
    type="submit"
    className="w-full py-3 md:py-2 md:w-auto md:px-6 bg-blue-600 text-white
               rounded-lg font-medium hover:bg-blue-700 active:bg-blue-800"
  >
    Save Tour
  </button>
</form>
```

---

## Performance Optimizations for Mobile

### Lazy Loading Images
```jsx
// Use native lazy loading
<img
  src={tour.image}
  alt={tour.title}
  loading="lazy"
  className="w-full h-48 object-cover"
/>
```

### Virtualized Lists for Large Data
```jsx
// For tours list with 100+ items, consider virtualization
import { FixedSizeList } from 'react-window';

const VirtualizedTourList = ({ tours }) => (
  <FixedSizeList
    height={window.innerHeight - 120}
    itemCount={tours.length}
    itemSize={100}
    width="100%"
  >
    {({ index, style }) => (
      <div style={style}>
        <TourCard tour={tours[index]} />
      </div>
    )}
  </FixedSizeList>
);
```

### Reduce Re-renders
```jsx
// Memoize expensive components
const TourCard = React.memo(({ tour, onSelect }) => {
  // Component content
});

// Memoize callbacks
const handleSelect = useCallback((tour) => {
  setSelectedTour(tour);
}, []);
```

### Optimize Bundle Size
```jsx
// Lazy load pages
const Tours = React.lazy(() => import('./pages/Tours'));
const Guides = React.lazy(() => import('./pages/Guides'));

// In App.jsx
<Suspense fallback={<LoadingSpinner />}>
  <Routes>
    <Route path="/tours" element={<Tours />} />
    <Route path="/guides" element={<Guides />} />
  </Routes>
</Suspense>
```

---

## Mobile Testing Checklist

### Touch Interactions
- [ ] All buttons have minimum 44x44px touch target
- [ ] Interactive elements have visible feedback (active states)
- [ ] No hover-only interactions (use click/tap)
- [ ] Swipe gestures don't conflict with scrolling

### Navigation
- [ ] Hamburger menu works smoothly
- [ ] Back navigation is clear
- [ ] Current page is indicated
- [ ] Deep links work correctly

### Forms
- [ ] Inputs use correct `inputMode` attribute
- [ ] No iOS zoom on focus (use 16px font minimum)
- [ ] Keyboard doesn't obscure inputs
- [ ] Submit buttons visible without scrolling

### Content
- [ ] Text is readable (min 14px)
- [ ] Images scale correctly
- [ ] Tables have horizontal scroll or card layout
- [ ] Long text truncates with ellipsis

### Modals/Overlays
- [ ] Modals use bottom sheet on mobile
- [ ] Can be dismissed by swipe or tap outside
- [ ] Content is scrollable
- [ ] Keyboard doesn't break layout

### Performance
- [ ] Page loads under 3 seconds on 3G
- [ ] Images lazy load
- [ ] No layout shift during load
- [ ] Smooth scrolling (60fps)

---

## Responsive Audit Commands

Use these patterns to identify mobile issues in existing files:

```jsx
// Look for hardcoded widths
grep -r "w-\[" src/

// Look for fixed heights that might break on mobile
grep -r "h-\[" src/

// Find non-responsive text sizes
grep -r "text-[2-9]xl" src/ | grep -v "md:" | grep -v "lg:"

// Find potential touch target issues
grep -r "p-1\b\|p-2\b" src/
```

### Common Fixes

```jsx
// BEFORE: Fixed width sidebar
<aside className="w-64">

// AFTER: Responsive sidebar
<aside className="w-64 hidden lg:block">

// BEFORE: Small touch targets
<button className="p-1">

// AFTER: Proper touch targets
<button className="p-2 min-h-[44px] min-w-[44px]">

// BEFORE: Non-responsive grid
<div className="grid grid-cols-4 gap-4">

// AFTER: Responsive grid
<div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
```
