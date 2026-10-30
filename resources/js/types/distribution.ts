export type ScheduleException = {
    date: string;
    works: boolean;
};

/** Participation and schedule of one employee in the selected showroom only. */
export type DistributionMemberRow = {
    id: number;
    name: string;
    active: boolean;
    participates: boolean;
    /** Monday first, seven days. */
    weekdays: boolean[];
    exceptions: ScheduleException[];
    lock_version: number;
    /** The employee's other showrooms with a schedule; only for a manager. */
    copy_from: { id: number; name: string }[];
};

export type DistributionStateValue = 'pending' | 'expired';

export type OrderDistribution = {
    state: DistributionStateValue;
    label: string;
};
