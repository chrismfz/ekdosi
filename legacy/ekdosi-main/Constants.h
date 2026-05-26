//---------------------------------------------------------------------------

#ifndef Constants
#define Constants

//Application Name
const char ApplicationName[] = "Έκδοση";
//Encoding key
const char CipherKey[] = "e9e65b53fdf7df86940eb6192dce923ef7644e29";
//Constant for the RGB color of the Odd Cells
const int OddCellsRed = 234, OddCellsGreen = 217, OddCellsBlue = 222;
//Constant for the RGB color of the Even Cells
const int EvenCellsRed = 196, EvenCellsGreen = 196, EvenCellsBlue = 222;
//Constant for the RGB color of the selected row
const int SelCellsRed = 109, SelCellsGreen = 226, SelCellsBlue = 155;
//Const for the IN database cells - for professor availability
const int UnAvailRed = 255, UnAvailGreen = 48, UnAvailBlue = 54;
//Minimum characters for password
const int MinCharPassAllowed = 6;
//Interval for timer in Main Form
const int MainTimerInterval = 400;
//GROUP_ID
const int DB_GROUP_ID = 47;
//Set right spacing for the GridTimetable
const int RIGHT_SPACING = 10;

//Search System Constants
#define SEARCH_SPC_BTWN 24
#define SEARCH_HORZ_SPC 8
#define SEARCH_VERTL_SPC 5

//Delay in milliseconds to invoke search
#define INVOKE_SEARCH_DELAY 400
#define SHOW_RECORDS_FETCHED_DELAY 600

#define YES 1
#define NO 0
#define SHOW_PDF_TAB YES
#define SDAP NO
#define CSCART_SYNC NO
#define PROTIMOLOGIO YES
//---------------------------------------------------------------------------
#endif

