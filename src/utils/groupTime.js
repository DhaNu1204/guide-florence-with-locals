// Step 6.16: the time a group (departure) leaves at.
//
// A manual merge can join bookings booked at different times (a 14:30 booking moved into the
// 12:30 departure). The owner then picks the time the group really leaves at; the server keeps
// it in tour_groups.departure_time. NULL = old behaviour: tour_groups.group_time.

const hhmm = (t) => (t ? String(t).substring(0, 5) : '');

// The group's time as every screen shows it.
export const groupDepartureTime = (group) =>
  hhmm(group && (group.departure_time || group.group_time));

// "HH:MM" when this member booked a different time than its group leaves at, else null.
export const memberBookedTime = (tour, group) => {
  const own = hhmm(tour && tour.time);
  const dep = groupDepartureTime(group);
  return own && dep && own !== dep ? own : null;
};

// The distinct booked times of the bookings being merged, earliest first (cancelled ones too:
// they are moved into the group as well, but they never decide anything on their own).
export const mergeTimes = (tours) =>
  [...new Set((tours || []).map((t) => hhmm(t && t.time)).filter(Boolean))].sort();

// What the merge dialog suggests: the time of the departure being merged INTO - a group's own
// departure time, or the booking's time. Falls back to the earliest time.
export const defaultMergeTime = (target, times) => {
  const t = target && target.type === 'group' ? groupDepartureTime(target.group) : hhmm(target && target.tour && target.tour.time);
  if (t && (times || []).includes(t)) return t;
  return (times && times[0]) || '';
};
