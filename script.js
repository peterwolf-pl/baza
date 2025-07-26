// script.js

function toggleColumn(column) {
    // Get all table headers and cells by column class
    let header = document.querySelector(`th.${column}`);
    let cells = document.querySelectorAll(`td.${column}`);
    let displayStyle = header.style.display === 'none' ? '' : 'none';

    // Toggle display of header and cells
    header.style.display = displayStyle;
    cells.forEach(cell => {
        cell.style.display = displayStyle;
    });
}
