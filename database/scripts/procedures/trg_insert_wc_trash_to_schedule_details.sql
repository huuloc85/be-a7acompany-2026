drop procedure if exists trg_insert_wc_trash_to_schedule_details;
CREATE PROCEDURE trg_insert_wc_trash_to_schedule_details(IN p_employee_id BIGINT, IN p_celender_id BIGINT)
BEGIN
    -- Use a single query with UNION to handle all weeks at once without dynamic SQL
    INSERT INTO schedule_details (employee_id, schedule_id, date, is_wc_trash, created_at, updated_at)
    SELECT 
        wc_men.employee_id,
        wc_men.celender_id,
        DATE_ADD(c.date, INTERVAL ((7 - DAYOFWEEK(c.date)) % 7 + (weeks.week_num - 1) * 7) DAY) as date,
        CASE weeks.week_num
            WHEN 1 THEN IF(wc_men.day1 IS NOT NULL AND wc_men.day1 != '', 1, 0)
            WHEN 2 THEN IF(wc_men.day2 IS NOT NULL AND wc_men.day2 != '', 1, 0)
            WHEN 3 THEN IF(wc_men.day3 IS NOT NULL AND wc_men.day3 != '', 1, 0)
            WHEN 4 THEN IF(wc_men.day4 IS NOT NULL AND wc_men.day4 != '', 1, 0)
            WHEN 5 THEN IF(wc_men.day5 IS NOT NULL AND wc_men.day5 != '', 1, 0)
        END as is_wc_trash,
        NOW(),
        NOW()
    FROM celender_detail_wc wc_men
    LEFT JOIN celenders c ON wc_men.celender_id = c.id
    CROSS JOIN (
        SELECT 1 as week_num UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5
    ) weeks
    WHERE wc_men.employee_id = p_employee_id 
      AND wc_men.celender_id = p_celender_id
      AND YEAR(c.date) = YEAR(DATE_ADD(c.date, INTERVAL ((7 - DAYOFWEEK(c.date)) % 7 + (weeks.week_num - 1) * 7) DAY))
      AND MONTH(c.date) = MONTH(DATE_ADD(c.date, INTERVAL ((7 - DAYOFWEEK(c.date)) % 7 + (weeks.week_num - 1) * 7) DAY))
    ON DUPLICATE KEY
        UPDATE is_wc_trash = VALUES(is_wc_trash),
               updated_at = NOW();
END;
