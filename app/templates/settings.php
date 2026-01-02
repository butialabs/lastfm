<?php $this->layout('layout', ['bodyClass' => 'page-settings']); ?>

<?php $flashMessage = flash('flash'); ?>

	<div class="admin box">
		<div class="configure">
			<div>Configure:</div>
			<div>
				<p>
					<?php if(($user['protocol'] ?? '') === 'at') { ?>
						<a href="https://bsky.app/profile/<?php echo htmlspecialchars($user['username'] ?? '', ENT_QUOTES); ?>" target="_blank">@<?php echo htmlspecialchars($user['username'] ?? '', ENT_QUOTES); ?></a>
					<?php } ?>
					<?php if(($user['protocol'] ?? '') === 'mastodon') { ?>
						<a href="https://<?php echo htmlspecialchars($user['instance'] ?? '', ENT_QUOTES); ?>/@<?php echo htmlspecialchars($user['username'] ?? '', ENT_QUOTES); ?>" target="_blank">@<?php echo htmlspecialchars($user['username'] ?? '', ENT_QUOTES); ?></a>
					<?php } ?>
				</p>
				<form id="lastfm" method="post" action="/logout" >
					<button type="submit"><?php echo htmlspecialchars(__('settings.logout'), ENT_QUOTES); ?></button>
				</form>
			</div>
		</div>

		<?php
			$haveData = false;
			if( isset($user['lastfm_username']) && isset($user['day_of_week']) && isset($user['time']) && isset($user['timezone']) && $user['lastfm_username'] !== '' ) {
				$haveData = true;
			}
		?>

		<?php if ($flashMessage) { ?>
			<div class="alert alert--info">
				<?php echo htmlspecialchars($flashMessage, ENT_QUOTES); ?>
			</div>
		<?php } ?>

		<?php if( $haveData == true ) { ?>
			<div class="edit">
				<div>
					<?php echo htmlspecialchars(__('settings.lastfm_username'), ENT_QUOTES); ?>
					<p><?php echo htmlspecialchars($user['lastfm_username'], ENT_QUOTES); ?></p>
				</div>
				<div>
					<?php echo htmlspecialchars(__('settings.day_of_week'), ENT_QUOTES); ?>
					<p>
						<?php
							$day = (int)($user['day_of_week'] ?? 7);
							$dayKey = match($day) {
								7 => 'day.sunday',
								1 => 'day.monday',
								2 => 'day.tuesday',
								3 => 'day.wednesday',
								4 => 'day.thursday',
								5 => 'day.friday',
								6 => 'day.saturday',
								default => 'day.sunday',
							};
							echo htmlspecialchars(__($dayKey), ENT_QUOTES);
						?>
					</p>
				</div>
				<div>
					<?php echo htmlspecialchars(__('settings.hour'), ENT_QUOTES); ?>
					<p>
						<?php echo htmlspecialchars($userHour ?? '09:00', ENT_QUOTES); ?>
						(<?php echo htmlspecialchars($user['timezone'] ?? 'UTC', ENT_QUOTES); ?>)
					</p>
				</div>
				<div>
					<button id="edit"><?php echo htmlspecialchars(__('settings.edit'), ENT_QUOTES); ?></button>
				</div>
			</div>
		<?php } ?>

		<div class="save" style="display: <?php echo $haveData ? 'none' : 'flex' ?>">
			<form id="save" method="post" action="/settings" >
				<div class="form-fill">
					<div class="form-row">
						<input type="text" id="lastfm_username" name="lastfm_username" placeholder="<?php echo htmlspecialchars(__('settings.lastfm_username'), ENT_QUOTES); ?>" value="<?php echo htmlspecialchars($user['lastfm_username'] ?? '', ENT_QUOTES); ?>" required>
						<select id="day_of_week" name="day_of_week" required>
							<?php $day = (int)($user['day_of_week'] ?? 7); ?>
							<option value="7" <?php if ($day === 7) echo 'selected'; ?>><?php echo htmlspecialchars(__('day.sunday'), ENT_QUOTES); ?></option>
							<option value="1" <?php if ($day === 1) echo 'selected'; ?>><?php echo htmlspecialchars(__('day.monday'), ENT_QUOTES); ?></option>
							<option value="2" <?php if ($day === 2) echo 'selected'; ?>><?php echo htmlspecialchars(__('day.tuesday'), ENT_QUOTES); ?></option>
							<option value="3" <?php if ($day === 3) echo 'selected'; ?>><?php echo htmlspecialchars(__('day.wednesday'), ENT_QUOTES); ?></option>
							<option value="4" <?php if ($day === 4) echo 'selected'; ?>><?php echo htmlspecialchars(__('day.thursday'), ENT_QUOTES); ?></option>
							<option value="5" <?php if ($day === 5) echo 'selected'; ?>><?php echo htmlspecialchars(__('day.friday'), ENT_QUOTES); ?></option>
							<option value="6" <?php if ($day === 6) echo 'selected'; ?>><?php echo htmlspecialchars(__('day.saturday'), ENT_QUOTES); ?></option>
						</select>
					</div>
					<div class="form-row">
						<input type="time" id="hour" name="hour" value="<?php echo htmlspecialchars($userHour ?? '09:00', ENT_QUOTES); ?>" required>
						<select name="timezone" id="timezone" required>
							<?php $tzCurrent = (string)($user['timezone'] ?? 'UTC'); ?>
							<?php foreach (($timezones ?? []) as $tz): ?>
								<option value="<?php echo htmlspecialchars($tz, ENT_QUOTES); ?>" <?php echo $tz === $tzCurrent ? 'selected' : ''; ?>>
									<?php echo htmlspecialchars($tz, ENT_QUOTES); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
				<button type="submit" id="save_button"><?php echo htmlspecialchars(__('settings.save'), ENT_QUOTES); ?></button>
			</form>
		</div>
		<?php if (isset($user['social_message']) && $user['social_message']) { ?>
			<div class="social_message alert alert--secondary">
				<?php echo htmlspecialchars(__('settings.last_update'), ENT_QUOTES); ?>: <strong><?php echo htmlspecialchars($user['social_message'], ENT_QUOTES); ?></strong>
				<?php if (isset($user['social_montage']) && $user['social_montage']) { ?>
					<?php echo htmlspecialchars(__('settings.with_montage'), ENT_QUOTES); ?> <strong><a href="<?php echo htmlspecialchars($user['social_montage'], ENT_QUOTES); ?>" target="_blank"><?php echo htmlspecialchars(__('settings.montage'), ENT_QUOTES); ?></a></strong>
				<?php } ?>
			</div>
		<?php } ?>

		<?php if (isset($statusText) && $statusText) { ?>
			<div class="alert alert--after alert--info">
				<strong><?php echo htmlspecialchars($statusText, ENT_QUOTES); ?></strong>
			</div>
		<?php } ?>

		<div class="delete">
			<form id="delete_account" method="post" action="/account/delete" >
				<button type="submit" id="delete_button" data-confirm="<?php echo htmlspecialchars(__('settings.confirm_delete'), ENT_QUOTES); ?>"><?php echo htmlspecialchars(__('settings.remove_account'), ENT_QUOTES); ?></button>
			</form>
		</div>
	</div>
