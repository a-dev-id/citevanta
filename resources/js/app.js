import * as bootstrap from 'bootstrap';
import '@fortawesome/fontawesome-free/css/all.min.css';
import { initSidebar } from './sidebar';
import { initTooltips } from './tooltips';
import { initCharts } from './charts';
import { initAnchors } from './anchors';
import { initCopy } from './copy';

window.bootstrap = bootstrap;

initSidebar();
initTooltips(bootstrap);
initCharts();
initAnchors(bootstrap);
initCopy();
