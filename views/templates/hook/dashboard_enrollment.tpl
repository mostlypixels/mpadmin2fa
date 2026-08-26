<section id="mpadmin2fa-dashboard" class="panel widget">
  <div class="panel-heading">
    <i class="icon-lock"></i>
    {$title|escape:'html':'UTF-8'}
  </div>
  <p class="alert {if $has_unenrolled}alert-warning{else}alert-success{/if}" role="status">
    {$message_prefix|escape:'html':'UTF-8'}
    <a class="alert-link" href="{$enrollment_url|escape:'html':'UTF-8'}">{$enrollment_link_label|escape:'html':'UTF-8'}</a>.
  </p>
  {if $can_view_security_activity}
    <div class="mpadmin2fa-security-activity">
      <h4>
        {$security_activity_title|escape:'html':'UTF-8'}
      </h4>
      {if $security_events}
        <div class="table-responsive">
          <table class="table table-bordered table-condensed">
            <thead class="sr-only">
              <tr>
                <th scope="col">{$security_event_column_label|escape:'html':'UTF-8'}</th>
                <th scope="col">{$security_occurrences_column_label|escape:'html':'UTF-8'}</th>
              </tr>
            </thead>
            <tbody>
              {foreach $security_events as $event}
                <tr>
                  <td>
                    <strong>{$event.event_label|escape:'html':'UTF-8'}</strong>
                    <small class="text-muted" style="display: block;">
                      {$event.employee|escape:'html':'UTF-8'} · {$event.date_add|escape:'html':'UTF-8'}
                    </small>
                  </td>
                  <td class="text-right" style="width: 1%; white-space: nowrap; vertical-align: middle;">
                    {if $event.occurrence_label}<span class="text-muted">{$event.occurrence_label|escape:'html':'UTF-8'}</span>{/if}
                  </td>
                </tr>
              {/foreach}
            </tbody>
          </table>
        </div>
        <div class="text-right">
          <a class="btn btn-default btn-sm" href="{$security_activity_url|escape:'html':'UTF-8'}">
            {$security_activity_see_all|escape:'html':'UTF-8'}
          </a>
        </div>
      {else}
        <p class="text-muted">{$security_activity_empty|escape:'html':'UTF-8'}</p>
      {/if}
    </div>
  {/if}
</section>
