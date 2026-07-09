<?php
/**
 * system.php — K6DRK iGate v5.0 system information page
 *
 * Displays Raspberry Pi hardware stats (CPU, memory, temperature, disk)
 * and service status for Direwolf and the APRS daemon. Used by operators
 * to monitor iGate health remotely.
 * Based on the Direwolf APRS Web Dashboard (PC7MM / PD3RFR / IZ7BOJ).
 */
// This file is part of the Direwolf APRS Web Dashboard as available at https://github.com/PC7MM/Direwolf-APRS-Web-Dashboard
// Developed by Michael PC7MM and Richard PD3RFR as an extension of https://github.com/IZ7BOJ/direwolf_webstat and https://github.com/IZ7BOJ/APRS_dashbo>
// See config.php for adjustable parameters and see https://www.youtube.com/watch?v=7bMf7rWCfnE for more information
// Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
// ©2025 Doug Kaye, K6DRK <doug@rds.com>

include 'initialize.php';

if(isset($_GET['ajax'])) {
        $mem=report_freemem ();

        echo('<br><center><table class="normaltable systemtable"><tbody>');
        echo('<tr><th colspan="2">System Status</th></tr>');
        echo('<tr><td><b>Hostname</b></td><td>'.report_hostname().'</td></tr>');
        echo('<tr><td><b>Operating System</b></td><td>'.report_sysver().'</td></tr>');
        echo('<tr><td><b>Kernel Version</b></td><td>'.report_kernelver().'</td></tr>');
        echo('<tr><td><b>Direwolf Version</b></td><td>'.report_direwolfversion().'</td></tr>');
        echo('<tr><td><b>System Uptime</b></td><td>'.report_uptime().'</td></tr>');
        echo('<tr><td><b>CPU Temperature</b></td><td>'.report_cputemp().'°C </td></tr>');
        echo('<tr><td><b>CPU Frequency</b></td><td>'.report_cpufreq().' MHz </td></tr>');
        echo('<tr><td><b>CPU Utilization</b></td><td>'.report_cpuutil().'</td></tr>');
        echo('<tr><td><b>Memory Usage</b></td><td>'.$mem[0].'MB of total '.$mem[1].' MB ('.$mem[2].'%)</td></tr>');
        echo('<tr><td><B>Connected APRS-IS server</b></td><td>'.report_aprsisserver($dummy).'</td></tr>');
        echo('<tr><td><b>Direwolf Status</b></td><td>'.report_direwolfstatus().'</td></tr>');
        echo('<tr><td><b>iGate Operator</b></td><td class="station"><a href="https://qrz.com/db/'.$sysopcallsign.'" target="_new">'.$sysopcallsign.'</a></td></tr>');
        echo('<tr><td><b>Dashboard Version</b></td><td>'.$dashboardversion.'</td></tr>');
        echo('<tr><td><b>Dashboard Users</b></td><td>'.report_clientips().'</td></tr>');
        echo('</td></tr></tbody></table><BR><BR>');
        exit();

}
include('menu.php');
?>
<div class="page">
<center>
<div id="ajaxcontent"><BR><BR><B><I>Please wait while first System Status is being prepared</I></B></div>
<BR><BR>
<?php if (!str_contains($logourl,"direwolflogo.png")) echo('<img src="direwolflogo.png" width=100>'); ?>
</center>
</div>
</body>
</html>