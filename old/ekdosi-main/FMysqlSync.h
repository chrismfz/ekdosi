//---------------------------------------------------------------------------

#ifndef FMysqlSyncH
#define FMysqlSyncH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>
#include <DB.hpp>
#include <SqlExpr.hpp>
#include <WideStrings.hpp>
#include <FMTBcd.hpp>
#include <ExtCtrls.hpp>
#include <DBGrids.hpp>
#include <Grids.hpp>
#include "JvExControls.hpp"
#include "JvSpecialProgress.hpp"
#include "JvDotNetControls.hpp"
#include "JvAppStorage.hpp"
#include "JvComponentBase.hpp"
#include "JvThreadTimer.hpp"
#include "JvThread.hpp"
#include "JvDBDotNetControls.hpp"
#include <DBCtrls.hpp>
#include <IBX.IBCustomDataSet.hpp>
#include <IBX.IBQuery.hpp>
#include <DBXMySQL.hpp>
//---------------------------------------------------------------------------

#include <vector>

#include "CNewSpecialForm.h"
using namespace std;
class TFrmMySqlSync : public NewSpecialForm
{
__published:	// IDE-managed Components
	TLabel *lblState;
	TLabel *lblShow;
	TIBQuery *QueryInsert;
	TIBQuery *QueryUpdate;
	TJvSpecialProgress *progress;
	TJvDotNetButton *cmdAuthentication;
	TJvDotNetButton *JvDotNetButton1;
	TSQLConnection *sqlConnection;
	TSQLQuery *Query;
	void __fastcall cmdAuthenticationClick(TObject *Sender);
	void __fastcall JvDotNetButton1Click(TObject *Sender);
private:	// User declarations
	AnsiString sqlQry;
	bool stopFlag;
	void fetchData();
	int getRecordCount();
	void checkFirebird();
	int getDefaultVatId();
	void changeState(vector<int> productId);
	int defaultVatCatId;
public:		// User declarations
	__fastcall TFrmMySqlSync(TComponent* Owner);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmMySqlSync *FrmMySqlSync;
//---------------------------------------------------------------------------
#endif
