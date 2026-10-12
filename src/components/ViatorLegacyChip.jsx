/**
 * Step 6.9: the booking came through the Viator account the owner is retiring.
 *
 * Grey on purpose - it is a fact about where the booking came from, not a warning and not a
 * problem. It matters because once he disconnects that account our copy of these bookings
 * stops being updated, so they are the ones he has to check by hand.
 *
 * Renders nothing at all for anything else, including bookings on the new Viator account.
 *
 * Step 6.18: `account` is the server's viatorAccountForDisplay() value, worked out from the
 * Viator supplier number in the product code. 'unknown' (no code, or a supplier number we do
 * not know) shows "Viator ?" so nothing is silently put on either side.
 */
const ViatorLegacyChip = ({ account, className = '' }) => {
  if (account === 'unknown') {
    return (
      <span
        className={`inline-block px-2 py-0.5 text-xs font-medium rounded-tuscan bg-stone-100 text-stone-700 border border-dashed border-stone-400 ${className}`}
        data-testid="viator-unknown-chip"
        title="We could not tell which Viator account this booking came from (no Viator product code). Check it in Viator."
      >
        Viator ?
      </span>
    );
  }
  if (account !== 'legacy') return null;

  return (
    <span
      className={`inline-block px-2 py-0.5 text-xs font-medium rounded-tuscan bg-stone-200 text-stone-700 ${className}`}
      data-testid="viator-legacy-chip"
      title="Booked through the old Viator account. Once it is disconnected this booking stops updating here - check it with Viator before the tour."
    >
      old Viator
    </span>
  );
};

export default ViatorLegacyChip;
